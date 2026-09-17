<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ $siteName }}</title>
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="video.other">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    @if ($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:alt" content="{{ $title }}">
        @if (str_starts_with($imageUrl, 'https://'))
            <meta property="og:image:secure_url" content="{{ $imageUrl }}">
        @endif
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if ($imageUrl)
        <meta name="twitter:image" content="{{ $imageUrl }}">
    @endif
</head>
<body>
    <article>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
        @if ($imageUrl)
            <p><img src="{{ $imageUrl }}" alt="{{ $title }}" width="600"></p>
        @endif
        <p><a href="{{ $playerUrl }}">Watch on {{ $siteName }}</a></p>
    </article>
    <script>
        window.location.replace(@json($canonicalUrl));
    </script>
</body>
</html>
