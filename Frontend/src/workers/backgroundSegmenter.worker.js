import {
  FilesetResolver,
  ImageSegmenter
} from "@mediapipe/tasks-vision";

const MODEL_URL =
  "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_multiclass_256x256/float32/latest/selfie_multiclass_256x256.tflite";

let segmenter = null;

async function initialize() {
  try {
    const vision =
    await FilesetResolver.forVisionTasks(
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.0.1/wasm"
    );

    segmenter = await ImageSegmenter.createFromOptions(
      vision,
      {
        baseOptions: {
          modelAssetPath: MODEL_URL,
          delegate: "CPU"
        },

        runningMode: "IMAGE",

        outputConfidenceMasks: true,
        outputCategoryMask: false
      }
    );

    self.postMessage({
      type: "ready"
    });

  } catch (error) {

    console.error(error);

    self.postMessage({
      type: "error",
      message: error?.message || String(error)
    });
  }
}

initialize();

self.onmessage = (event) => {

  const data = event.data;

  if (
    data.type !== "frame" ||
    !segmenter
  ) {
    return;
  }

  const bitmap = data.bitmap;

  const threshold = data.threshold ?? 0.35;
  const softness = data.softness ?? 0.18;

  const started = performance.now();

  try {

    segmenter.segment(
      bitmap,
      (result) => {

        try {

          const masks =
            result.confidenceMasks;

          if (
            !masks ||
            masks.length < 6
          ) {
            throw new Error(
              "Expected 6 confidence masks."
            );
          }

          const hair =
            masks[1].getAsFloat32Array();

          const bodySkin =
            masks[2].getAsFloat32Array();

          const face =
            masks[3].getAsFloat32Array();

          const clothes =
            masks[4].getAsFloat32Array();

          const accessories =
            masks[5].getAsFloat32Array();

          const width =
            masks[0].width;

          const height =
            masks[0].height;

          const alpha =
            new Uint8ClampedArray(
              width * height
            );

          const lower =
            Math.max(
              0,
              threshold - softness
            );

          const upper =
            Math.min(
              1,
              threshold + softness
            );

          for (
            let i = 0;
            i < hair.length;
            i++
          ) {

            let confidence =
              hair[i] +
              bodySkin[i] +
              face[i] +
              clothes[i] +
              accessories[i];

            confidence =
              Math.min(
                1,
                confidence
              );

            let a = 0;

            if (
              confidence <= lower
            ) {

              a = 0;

            } else if (
              confidence >= upper
            ) {

              a = 1;

            } else {

              let t =
                (confidence - lower) /
                (upper - lower);

              t =
                t * t *
                (3 - 2 * t);

              a = t;
            }

            alpha[i] =
              Math.round(
                a * 255
              );
          }

          self.postMessage(
            {
              type: "mask",
              width,
              height,
              alphaBuffer:
                alpha.buffer,

              inferenceMs:
                performance.now() -
                started
            },

            [
              alpha.buffer
            ]
          );

        } catch (error) {

          self.postMessage({
            type: "frame-error",
            message:
              error?.message ||
              String(error)
          });

        } finally {

          if (
            result.confidenceMasks
          ) {

            for (
              const mask of
              result.confidenceMasks
            ) {
              mask.close();
            }
          }

          if (
            result.categoryMask
          ) {
            result.categoryMask.close();
          }

          bitmap.close();
        }
      }
    );

  } catch (error) {

    bitmap.close();

    self.postMessage({
      type: "frame-error",
      message:
        error?.message ||
        String(error)
    });
  }
};