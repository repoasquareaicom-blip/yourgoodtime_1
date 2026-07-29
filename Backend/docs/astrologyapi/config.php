<?php

declare(strict_types=1);

const ASTROLOGYAPI_BASE_URL = 'https://json.astrologyapi.com/v1';

/** Wallet access token — prefer ASTROLOGYAPI_ACCESS_TOKEN env var in production. */
const ASTROLOGYAPI_ACCESS_TOKEN = 'ak-d78142ef2fd910c96752551713d9e7a4cb0ddc59';

/** SQLite cache file path; leave empty for astrologyapi/storage/astro_reports.sqlite */
const ASTRO_DB_PATH = '';

/** Age-based prediction Excel files; leave empty for project /data folder */
const PREDICTION_DATA_DIR = '';
