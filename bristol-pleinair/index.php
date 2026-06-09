<?php
declare(strict_types=1);

$csvPath = __DIR__ . '/exhibition.csv';

function cleanText(?string $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function loadArtworks(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        return [];
    }

    $artworks = [];
    while (($row = fgetcsv($handle)) !== false) {
        $row = array_pad($row, count($headers), '');
        $record = array_combine($headers, $row);
        if ($record === false) {
            continue;
        }

        $artist = cleanText($record['Artist Name'] ?? '');
        $painting = cleanText($record['Painting'] ?? '');

        if ($artist === '' || $painting === '') {
            continue;
        }

        $artworks[] = [
            'artist' => $artist,
            'thumbnail' => trim((string) ($record['Thumbnail'] ?? '')),
            'painting' => $painting,
            'size' => cleanText($record['Size'] ?? ''),
            'medium' => cleanText($record['Medium'] ?? ''),
            'price' => cleanText($record['Price'] ?? ''),
            'website' => cleanText($record['Website'] ?? ''),
            'instagram' => cleanText($record['Instagram'] ?? ''),
        ];
    }

    fclose($handle);
    return $artworks;
}

function availabilityLabel(string $price): string
{
    $normalisedPrice = strtolower($price);
    if (str_contains($normalisedPrice, 'not for sale')) {
        return 'Not for Sale';
    }

    if (str_contains($normalisedPrice, 'price on application')) {
        return 'Price on Application';
    }

    return 'Available';
}

function websiteUrl(string $website): string
{
    if ($website === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $website) === 1) {
        return $website;
    }

    return 'https://' . ltrim($website, '/');
}

function thumbnailUrl(string $thumbnail): string
{
    if ($thumbnail === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $thumbnail) === 1) {
        return $thumbnail;
    }

    if (preg_match('/^[A-Za-z0-9._\/-]+$/', $thumbnail) !== 1) {
        return '';
    }

    return 'images/' . ltrim($thumbnail, '/');
}

function renderInstagramLinks(string $instagram): string
{
    if ($instagram === '') {
        return '';
    }

    $parts = preg_split('/\s+(?:and|&)\s+|,\s*/i', $instagram) ?: [];
    $links = [];

    foreach ($parts as $part) {
        $handle = ltrim(trim($part), '@');
        if ($handle === '') {
            continue;
        }

        if (preg_match('/^[A-Za-z0-9._]+$/', $handle) === 1) {
            $links[] = '<a href="https://www.instagram.com/' . rawurlencode($handle) . '" target="_blank" rel="noopener">@' . e($handle) . '</a>';
        } else {
            $links[] = '<span>' . e($part) . '</span>';
        }
    }

    return implode('<span aria-hidden="true"> / </span>', $links);
}

$artworks = loadArtworks($csvPath);
$artists = array_values(array_unique(array_column($artworks, 'artist')));
natcasesort($artists);
$artists = array_values($artists);
$availabilityOptions = array_values(array_unique(array_map(
    static fn(array $artwork): string => availabilityLabel($artwork['price']),
    $artworks
)));
sort($availabilityOptions);
$artworkCount = count($artworks);
$artistCount = count($artists);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Browse the Bristol PleinAir Painters exhibition artworks, artist details, sizes, media, prices, and contact links.">
    <title>Bristol PleinAir Painters Exhibition</title>
    <style>
        :root {
            --petrol: #00353d;
            --petrol-deep: #001f24;
            --teal: #00a399;
            --coral: #ff5549;
            --cream: #f8f3e8;
            --ink: #172427;
            --muted: #637377;
            --card: #ffffff;
            --line: rgba(0, 53, 61, 0.14);
            color-scheme: light;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 12% 5%, rgba(0, 163, 153, 0.28), transparent 28rem),
                linear-gradient(160deg, var(--petrol) 0%, var(--petrol-deep) 42%, #f4efe3 42%, var(--cream) 100%);
            color: var(--ink);
        }

        a {
            color: inherit;
        }

        .page-shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 56px;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(260px, 0.55fr);
            gap: 28px;
            align-items: stretch;
            color: #fff;
            margin-bottom: 28px;
        }

        .hero-panel,
        .stats-panel,
        .filters,
        .art-card,
        .empty-state {
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 24px 60px rgba(0, 31, 36, 0.14);
        }

        .hero-panel {
            border-radius: 32px;
            padding: clamp(28px, 5vw, 56px);
            background:
                linear-gradient(135deg, rgba(0, 163, 153, 0.20), rgba(255, 85, 73, 0.12)),
                rgba(0, 31, 36, 0.88);
            overflow: hidden;
            position: relative;
        }

        .hero-panel::after {
            content: "";
            position: absolute;
            inset: auto -80px -120px auto;
            width: 260px;
            height: 260px;
            border-radius: 999px;
            background: rgba(255, 85, 73, 0.18);
            filter: blur(4px);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 18px;
            color: #bff0ec;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 34px;
            height: 2px;
            background: var(--coral);
            border-radius: 999px;
        }

        h1 {
            max-width: 780px;
            margin: 0;
            color: #fff;
            font-size: clamp(2.45rem, 8vw, 5.8rem);
            line-height: 0.94;
            letter-spacing: -0.07em;
        }

        .hero-copy {
            position: relative;
            z-index: 1;
            max-width: 720px;
            margin: 26px 0 0;
            color: rgba(255, 255, 255, 0.84);
            font-size: clamp(1rem, 2vw, 1.2rem);
            line-height: 1.7;
        }

        .stats-panel {
            display: grid;
            gap: 18px;
            align-content: center;
            border-radius: 32px;
            padding: 28px;
            background: rgba(255, 255, 255, 0.92);
            color: var(--petrol);
        }

        .stat {
            padding: 20px;
            border-radius: 24px;
            background: linear-gradient(135deg, rgba(0, 163, 153, 0.10), rgba(255, 85, 73, 0.08));
            border: 1px solid var(--line);
        }

        .stat strong {
            display: block;
            color: var(--petrol);
            font-size: clamp(2.2rem, 5vw, 3.75rem);
            line-height: 1;
            letter-spacing: -0.06em;
        }

        .stat span {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-weight: 700;
        }

        .filters {
            position: sticky;
            top: 16px;
            z-index: 10;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr 0.7fr;
            gap: 14px;
            margin: 0 0 26px;
            padding: 16px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(16px);
        }

        label {
            display: grid;
            gap: 8px;
            color: var(--petrol);
            font-size: 0.77rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        input,
        select {
            width: 100%;
            min-height: 46px;
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            outline: none;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }

        input:focus,
        select:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(0, 163, 153, 0.14);
        }

        .result-summary {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin: 0 0 18px;
            color: var(--petrol);
            font-weight: 800;
        }

        .result-summary p {
            margin: 0;
        }

        .source-link {
            color: var(--muted);
            font-size: 0.92rem;
            font-weight: 700;
            text-decoration: none;
        }

        .source-link:hover {
            color: var(--coral);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
        }

        .art-card {
            display: flex;
            flex-direction: column;
            min-height: 100%;
            overflow: hidden;
            border-color: var(--line);
            border-radius: 28px;
            background: var(--card);
        }

        .art-card.is-hidden {
            display: none;
        }

        .art-image {
            display: grid;
            place-items: center;
            min-height: 220px;
            aspect-ratio: 4 / 3;
            background:
                linear-gradient(135deg, rgba(0, 163, 153, 0.28), rgba(255, 85, 73, 0.20)),
                var(--petrol);
            color: #fff;
            overflow: hidden;
            position: relative;
        }

        .art-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .placeholder-mark {
            display: grid;
            place-items: center;
            width: 96px;
            height: 96px;
            border: 1px solid rgba(255, 255, 255, 0.32);
            border-radius: 999px;
            background: rgba(0, 31, 36, 0.20);
            color: #fff;
            font-size: 3.1rem;
            font-weight: 900;
            letter-spacing: -0.08em;
        }

        .art-body {
            display: flex;
            flex: 1;
            flex-direction: column;
            padding: 22px;
        }

        .artist-name {
            margin: 0 0 8px;
            color: var(--teal);
            font-size: 0.82rem;
            font-weight: 900;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .art-title {
            margin: 0;
            color: var(--petrol);
            font-size: 1.35rem;
            line-height: 1.18;
            letter-spacing: -0.035em;
        }

        .meta-list {
            display: grid;
            gap: 10px;
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
            color: var(--muted);
            font-size: 0.96rem;
        }

        .meta-list li {
            display: grid;
            grid-template-columns: 72px 1fr;
            gap: 10px;
        }

        .meta-list span:first-child {
            color: var(--petrol);
            font-weight: 800;
        }

        .availability {
            display: inline-flex;
            align-self: flex-start;
            margin-top: 18px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(0, 163, 153, 0.10);
            color: var(--petrol);
            font-size: 0.82rem;
            font-weight: 900;
        }

        .availability[data-availability="Not for Sale"] {
            background: rgba(99, 115, 119, 0.12);
            color: var(--muted);
        }

        .availability[data-availability="Price on Application"] {
            background: rgba(255, 85, 73, 0.12);
            color: #a8362f;
        }

        .links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding-top: 18px;
        }

        .links a,
        .links span {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--petrol);
            font-size: 0.9rem;
            font-weight: 800;
            text-decoration: none;
        }

        .links a:hover {
            border-color: var(--coral);
            color: var(--coral);
        }

        .empty-state {
            display: none;
            margin-top: 24px;
            padding: 32px;
            border-color: var(--line);
            border-radius: 28px;
            background: rgba(255, 255, 255, 0.92);
            color: var(--petrol);
            text-align: center;
        }

        .empty-state.is-visible {
            display: block;
        }

        .footer-note {
            margin: 36px auto 0;
            max-width: 780px;
            color: rgba(0, 31, 36, 0.70);
            text-align: center;
            line-height: 1.6;
        }

        @media (max-width: 860px) {
            .hero,
            .filters {
                grid-template-columns: 1fr;
            }

            .filters {
                position: static;
            }

            .result-summary {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 520px) {
            .page-shell {
                width: min(100% - 20px, 1180px);
                padding-top: 10px;
            }

            .hero-panel,
            .stats-panel,
            .filters,
            .art-card,
            .empty-state {
                border-radius: 22px;
            }

            .meta-list li {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }
    </style>
</head>
<body>
    <main class="page-shell">
        <section class="hero" aria-labelledby="page-title">
            <div class="hero-panel">
                <p class="eyebrow">Exhibition Final Paintings</p>
                <h1 id="page-title">Bristol PleinAir Painters</h1>
                <p class="hero-copy">
                    Browse the paintings selected for the Bristol PleinAir Painters exhibition, including artist details,
                    artwork dimensions, media, prices, and links to discover more from each artist.
                </p>
            </div>
            <aside class="stats-panel" aria-label="Exhibition summary">
                <div class="stat">
                    <strong><?= e((string) $artworkCount) ?></strong>
                    <span>Artworks listed</span>
                </div>
                <div class="stat">
                    <strong><?= e((string) $artistCount) ?></strong>
                    <span>Participating artists</span>
                </div>
            </aside>
        </section>

        <section class="filters" aria-label="Filter exhibition artworks">
            <label>
                Search
                <input id="searchInput" type="search" placeholder="Artist, painting, medium..." autocomplete="off">
            </label>
            <label>
                Artist
                <select id="artistFilter">
                    <option value="">All artists</option>
                    <?php foreach ($artists as $artist): ?>
                        <option value="<?= e($artist) ?>"><?= e($artist) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Availability
                <select id="availabilityFilter">
                    <option value="">All works</option>
                    <?php foreach ($availabilityOptions as $option): ?>
                        <option value="<?= e($option) ?>"><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>

        <div class="result-summary" aria-live="polite">
            <p><span id="resultCount"><?= e((string) $artworkCount) ?></span> works showing</p>
            <a class="source-link" href="https://docs.google.com/spreadsheets/d/1lXEAHNLMHvHSnaIg41Y9di1l8ICx8xlIemtPsbzayHM/edit?usp=sharing" target="_blank" rel="noopener">Source spreadsheet</a>
        </div>

        <section class="gallery-grid" id="galleryGrid" aria-label="Exhibition artworks">
            <?php foreach ($artworks as $artwork): ?>
                <?php
                $availability = availabilityLabel($artwork['price']);
                $image = thumbnailUrl($artwork['thumbnail']);
                $searchText = strtolower(cleanText(implode(' ', [
                    $artwork['artist'],
                    $artwork['painting'],
                    $artwork['size'],
                    $artwork['medium'],
                    $artwork['price'],
                    $artwork['website'],
                    $artwork['instagram'],
                ])));
                ?>
                <article
                    class="art-card"
                    data-artist="<?= e($artwork['artist']) ?>"
                    data-availability="<?= e($availability) ?>"
                    data-search="<?= e($searchText) ?>"
                >
                    <div class="art-image">
                        <?php if ($image !== ''): ?>
                            <img src="<?= e($image) ?>" alt="<?= e($artwork['painting'] . ' by ' . $artwork['artist']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="placeholder-mark" aria-hidden="true"><?= e(strtoupper(substr($artwork['artist'], 0, 1))) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="art-body">
                        <p class="artist-name"><?= e($artwork['artist']) ?></p>
                        <h2 class="art-title"><?= e($artwork['painting']) ?></h2>
                        <ul class="meta-list">
                            <?php if ($artwork['size'] !== ''): ?>
                                <li><span>Size</span><span><?= e($artwork['size']) ?></span></li>
                            <?php endif; ?>
                            <?php if ($artwork['medium'] !== ''): ?>
                                <li><span>Medium</span><span><?= e($artwork['medium']) ?></span></li>
                            <?php endif; ?>
                            <?php if ($artwork['price'] !== ''): ?>
                                <li><span>Price</span><span><?= e($artwork['price']) ?></span></li>
                            <?php endif; ?>
                        </ul>
                        <span class="availability" data-availability="<?= e($availability) ?>"><?= e($availability) ?></span>
                        <?php if ($artwork['website'] !== '' || $artwork['instagram'] !== ''): ?>
                            <div class="links" aria-label="Artist links">
                                <?php if ($artwork['website'] !== ''): ?>
                                    <a href="<?= e(websiteUrl($artwork['website'])) ?>" target="_blank" rel="noopener">Website</a>
                                <?php endif; ?>
                                <?= renderInstagramLinks($artwork['instagram']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="empty-state" id="emptyState">
            <h2>No works match these filters.</h2>
            <p>Try clearing the search field or choosing a different artist or availability.</p>
        </div>

        <p class="footer-note">
            Artwork details are taken from the exhibition spreadsheet. Prices and availability can change, so please
            use the artist links for the latest enquiries.
        </p>
    </main>

    <script>
        const cards = Array.from(document.querySelectorAll('.art-card'));
        const searchInput = document.getElementById('searchInput');
        const artistFilter = document.getElementById('artistFilter');
        const availabilityFilter = document.getElementById('availabilityFilter');
        const resultCount = document.getElementById('resultCount');
        const emptyState = document.getElementById('emptyState');

        function normalise(value) {
            return value.trim().toLowerCase();
        }

        function applyFilters() {
            const query = normalise(searchInput.value);
            const artist = artistFilter.value;
            const availability = availabilityFilter.value;
            let visibleCount = 0;

            cards.forEach((card) => {
                const matchesSearch = query === '' || card.dataset.search.includes(query);
                const matchesArtist = artist === '' || card.dataset.artist === artist;
                const matchesAvailability = availability === '' || card.dataset.availability === availability;
                const isVisible = matchesSearch && matchesArtist && matchesAvailability;

                card.classList.toggle('is-hidden', !isVisible);
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            resultCount.textContent = String(visibleCount);
            emptyState.classList.toggle('is-visible', visibleCount === 0);
        }

        [searchInput, artistFilter, availabilityFilter].forEach((control) => {
            control.addEventListener('input', applyFilters);
            control.addEventListener('change', applyFilters);
        });
    </script>
</body>
</html>
