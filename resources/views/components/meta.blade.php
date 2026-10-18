@if ($metadata->title !== null)
    <title>{{ $metadata->title }}</title>
@endif
@if ($metadata->description !== null)
    <meta name="description" content="{{ $metadata->description }}">
@endif
@if ($metadata->keywords !== null)
    <meta name="keywords" content="{{ $metadata->keywords }}">
@endif
@if ($metadata->canonicalUrl !== null)
    <link rel="canonical" href="{{ $metadata->canonicalUrl }}">
    <meta property="og:url" content="{{ $metadata->canonicalUrl }}">
@endif
@if ($hasStoredMetadata)
    <meta name="robots" content="{{ $metadata->robotsIndex ? 'index' : 'noindex' }}, {{ $metadata->robotsFollow ? 'follow' : 'nofollow' }}">
@endif
@if ($metadata->ogTitle !== null)
    <meta property="og:title" content="{{ $metadata->ogTitle }}">
@endif
@if ($metadata->ogDescription !== null)
    <meta property="og:description" content="{{ $metadata->ogDescription }}">
@endif
@if ($metadata->ogImageUrl !== null)
    <meta property="og:image" content="{{ $metadata->ogImageUrl }}">
    @if ($metadata->ogImageAlt !== null)
        <meta property="og:image:alt" content="{{ $metadata->ogImageAlt }}">
    @endif
@endif
@if ($metadata->twitterTitle !== null || $metadata->twitterDescription !== null || $metadata->twitterImageUrl !== null)
    <meta name="twitter:card" content="{{ $metadata->twitterImageUrl !== null ? 'summary_large_image' : 'summary' }}">
    @if ($metadata->twitterTitle !== null)
        <meta name="twitter:title" content="{{ $metadata->twitterTitle }}">
    @endif
    @if ($metadata->twitterDescription !== null)
        <meta name="twitter:description" content="{{ $metadata->twitterDescription }}">
    @endif
    @if ($metadata->twitterImageUrl !== null)
        <meta name="twitter:image" content="{{ $metadata->twitterImageUrl }}">
        @if ($metadata->twitterImageAlt !== null)
            <meta name="twitter:image:alt" content="{{ $metadata->twitterImageAlt }}">
        @endif
    @endif
@endif
