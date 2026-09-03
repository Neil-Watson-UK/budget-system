<?php
/**
 * Download MBR report as a PowerPoint (.pptx) — same data as mbr_report.php.
 * Requires: composer install (phpoffice/phppresentation) + PHP zip extension.
 */
session_start();
// Absorb any stray output from includes so .pptx bytes are not corrupted / headers not sent early.
if (ob_get_level() === 0) {
    ob_start();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('mbr_export_pptx.php'));
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MBR export</title></head><body>';
    echo '<p>PowerPoint export needs Composer dependencies. From this folder run:</p><pre>composer install</pre>';
    echo '<p><a href="mbr_report.php">Back to MBR Report</a></p>';
    echo '</body></html>';
    exit;
}

if (!extension_loaded('zip')) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(503);
    echo 'PowerPoint export requires the PHP zip extension (php-zip). Ask your host to enable it.';
    exit;
}

require_once $autoload;

if (!class_exists('PhpOffice\PhpPresentation\PhpPresentation')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(503);
    $v = __DIR__ . '/vendor';
    $ppClass = $v . '/phpoffice/phppresentation/src/PhpPresentation/PhpPresentation.php';
    $commonDir = $v . '/phpoffice/common/src/Common';
    $hasPp = is_file($ppClass);
    $hasCommon = is_dir($commonDir);
    $autoloadHasPp = is_readable($v . '/autoload.php')
        && str_contains((string) @file_get_contents($v . '/autoload.php'), 'PhpPresentation');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MBR export</title></head><body style="font-family:sans-serif;max-width:42rem;margin:2rem auto;line-height:1.5">';
    echo '<h1 style="font-size:1.15rem;">PowerPoint library not installed</h1>';
    echo '<p>This server cannot load <strong>PhpOffice\\PhpPresentation</strong>. Deploy the package and a working Composer autoload (see below).</p>';
    echo '<p style="font-size:0.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.75rem 1rem;"><strong>On this server right now:</strong><br>';
    echo '· <code>vendor/phpoffice/phppresentation/.../PhpPresentation.php</code> — ' . ($hasPp ? '<span style="color:#15803d">found</span>' : '<span style="color:#b91c1c">missing</span>') . '<br>';
    echo '· <code>vendor/phpoffice/common/</code> — ' . ($hasCommon ? '<span style="color:#15803d">found</span>' : '<span style="color:#b91c1c">missing</span>') . '<br>';
    echo '· <code>vendor/autoload.php</code> mentions PhpPresentation — ' . ($autoloadHasPp ? '<span style="color:#15803d">yes</span>' : '<span style="color:#b91c1c">no</span> (upload the autoload from your PC or run Composer)') . '<br>';
    echo '<em>Linux paths are case-sensitive — folder must be <code>phppresentation</code> (lowercase).</em></p>';
    echo '<p><strong>Fix (FTP / File Manager — no SSH required)</strong></p><ol style="margin:0 0 1rem 1.25rem;padding:0;">';
    echo '<li>On your PC, in the <code>budget</code> folder, run <code>composer install</code> (or <code>composer require phpoffice/phppresentation:^1.1</code>) so <code>vendor</code> is complete.</li>';
    echo '<li>Zip the <strong>entire</strong> <code>vendor</code> folder and upload to <code>public_html/budgets</code> (backup old <code>vendor</code> first), then extract.</li>';
    echo '<li>Upload this file too if it is not inside the zip: <code>vendor/autoload.php</code> must register PhpPresentation (or use Composer’s generated autoload).</li>';
    echo '</ol>';
    echo '<p><strong>Option — cPanel Terminal</strong> (if enabled): <code>cd</code> to this app folder, then <code>composer install</code>.</p>';
    echo '<p><a href="mbr_report.php">Back to MBR Report</a></p>';
    echo '</body></html>';
    exit;
}

use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * Strip control chars and normalize text for PhpPresentation OOXML output.
 * The library's writer drops some Unicode (notably U+20AC € and U+2014 —), leaving empty <a:t> runs;
 * PowerPoint Online then shows headings only. Use ASCII-safe substitutes.
 */
function mbr_pptx_safe_text(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = str_replace(['€', '—', '–'], ['EUR ', ' - ', '-'], $s);
    return $s;
}

/**
 * Plain-language error text + optional technical block for admins (debug=1).
 */
function mbr_pptx_format_failure(\Throwable $e, bool $adminDebug): string
{
    $msg = $e->getMessage();
    $lcMsg = strtolower($msg);
    $class = get_class($e);

    $lines = [];
    // "ZipArchiveAdapter" contains "ziparchive" but means missing phpoffice/common class — not ext-zip.
    if (str_contains($msg, 'ZipArchiveAdapter') && str_contains($lcMsg, 'not found')) {
        $lines[] = 'The phpoffice/common package on the server is incomplete (missing Adapter/Zip classes).';
        $lines[] = 'Fix: on your PC run `composer install` in the budget folder, then upload the full `vendor/phpoffice/common` folder (or the whole `vendor` zip).';
    } elseif ((str_contains($lcMsg, 'ziparchive') && !str_contains($msg, 'ZipArchiveAdapter')) || preg_match('/\bzip\b.*(open|fail|error)/i', $msg)) {
        $lines[] = 'The server cannot create a .pptx file because the PHP zip extension is missing or broken.';
        $lines[] = 'Fix: enable the php-zip extension in PHP (often called "zip" in cPanel / PHP selector).';
    } elseif (str_contains($lcMsg, 'tempnam') || str_contains($lcMsg, 'temporary') || str_contains($class, 'FileCopy') || str_contains($class, 'FileRemove')) {
        $lines[] = 'The server could not write a temporary file for the presentation.';
        $lines[] = 'Fix: ensure sys_temp_dir is writable, or set open_basedir to allow the system temp folder.';
    } elseif (str_contains($class, 'PDO') || $e instanceof PDOException) {
        $lines[] = 'Database error while loading MBR data.';
        $lines[] = 'Fix: check DB connectivity and error logs; try again after the database is reachable.';
    } elseif (str_contains($lcMsg, 'memory') || str_contains($lcMsg, 'allowed memory')) {
        $lines[] = 'PHP ran out of memory building the presentation.';
        $lines[] = 'Fix: raise memory_limit for this site or export a smaller year range.';
    } elseif (str_contains($lcMsg, 'class') && str_contains($lcMsg, 'not found')) {
        $lines[] = 'A required library class was not found.';
        $lines[] = 'Fix: run `composer install` in the budget folder so phpoffice/phppresentation is installed.';
    } elseif (str_contains($lcMsg, 'undefined method') || str_contains($lcMsg, 'call to undefined')) {
        $lines[] = 'Presentation library mismatch (wrong or incomplete PhpPresentation version).';
        $lines[] = 'Fix: run `composer update phpoffice/phppresentation` and redeploy the vendor folder.';
    } else {
        $lines[] = 'Something went wrong while generating the PowerPoint file.';
        $lines[] = 'Common causes: PHP zip extension off; temp directory not writable; Composer packages missing on the server.';
    }

    $out = implode("\n", $lines);

    if ($adminDebug) {
        $out .= "\n\n--- Technical (admin only) ---\n";
        $out .= $class . "\n";
        $out .= $msg . "\n";
        $out .= $e->getFile() . ':' . $e->getLine() . "\n";
        if ($e->getPrevious()) {
            $out .= 'Previous: ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() . "\n";
        }
    }

    return $out;
}

$pdo = getDBConnection();
$selected_year = $_GET['year'] ?? date('Y');
if ($selected_year === 'all' || $selected_year === '') {
    $selected_year = date('Y');
}

$filename = 'MBR_Report_' . preg_replace('/[^0-9]/', '', (string) $selected_year) . '_' . date('Y-m-d') . '.pptx';

try {
    $regionCards = getMbrReportRegionCards($pdo, $selected_year);

    $fmtEur = function ($amt) {
        // Prefix "EUR" not the € character — PhpPresentation omits U+20AC from slide XML (empty runs).
        return 'EUR ' . number_format((float) $amt, 0, '.', ',');
    };

    $appName = defined('APP_NAME') ? APP_NAME : 'Budget';
    $present = new PhpPresentation();
    $present->getDocumentProperties()
        ->setCreator($appName)
        ->setTitle('MBR Report ' . $selected_year)
        ->setSubject('Management business review (EUR)');

    // Slide 1: title
    $slide1 = $present->getActiveSlide();
    $titleBox = $slide1->createRichTextShape()
        ->setHeight(120)
        ->setWidth(850)
        ->setOffsetX(50)
        ->setOffsetY(160);
    $titleBox->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $titleBox->createTextRun('MBR Report')->getFont()->setBold(true)->setSize(40)->setColor(new Color('FF00353D'));
    $titleBox->createBreak();
    $titleBox->createTextRun('Year ' . $selected_year . ' · All amounts in EUR')->getFont()->setSize(18)->setColor(new Color('FF475569'));
    $titleBox->createBreak();
    $titleBox->createTextRun('Generated ' . date('d M Y'))->getFont()->setSize(12)->setColor(new Color('FF94A3B8'));

    // Slide 2: regional summary (plain text — avoids table writer edge cases on some hosts)
    $slide2 = $present->createSlide();
    $sumBox = $slide2->createRichTextShape()
        ->setHeight(480)
        ->setWidth(880)
        ->setOffsetX(40)
        ->setOffsetY(36);
    $sumBox->createTextRun('Regional summary (EUR)')->getFont()->setBold(true)->setSize(22)->setColor(new Color('FF00353D'));
    $sumBox->createBreak();
    $sumBox->createBreak();
    foreach ($regionCards as $c) {
        $pp = isset($c['planned_pct_budget']) ? ' (' . $c['planned_pct_budget'] . '%)' : '';
        $cp = isset($c['committed_pct_budget']) ? ' (' . $c['committed_pct_budget'] . '%)' : '';
        $ip = isset($c['invoiced_pct_budget']) ? ' (' . $c['invoiced_pct_budget'] . '%)' : '';
        $tp = isset($c['total_spend_pct_budget']) ? ' · Total ' . $c['total_spend_pct_budget'] . '% of budget' : '';
        $line = sprintf(
            '%s — Planned %s%s · Committed %s%s · Invoiced %s%s · Total spend %s%s · Budget %s · Remaining %s',
            $c['region'],
            $fmtEur($c['planned_eur']),
            $pp,
            $fmtEur($c['committed_eur']),
            $cp,
            $fmtEur($c['invoiced_eur']),
            $ip,
            $fmtEur($c['total_used_eur']),
            $tp,
            $fmtEur($c['budget_eur']),
            $fmtEur($c['remaining_eur'])
        );
        $sumBox->createTextRun(mbr_pptx_safe_text($line))->getFont()->setSize(11)->setColor(new Color('FF0F172A'));
        $sumBox->createBreak();
    }

    // One slide per region (metrics + partners)
    foreach ($regionCards as $c) {
        $s = $present->createSlide();
        $head = $s->createRichTextShape()
            ->setHeight(48)
            ->setWidth(880)
            ->setOffsetX(40)
            ->setOffsetY(24);
        $head->createTextRun(mbr_pptx_safe_text($c['region'] . ' — ' . $c['title']))
            ->getFont()->setBold(true)->setSize(20)->setColor(new Color('FF00353D'));

        $body = $s->createRichTextShape()
            ->setHeight(380)
            ->setWidth(880)
            ->setOffsetX(40)
            ->setOffsetY(80);

        $pp = isset($c['planned_pct_budget']) ? ' (' . $c['planned_pct_budget'] . '% of budget)' : '';
        $cp = isset($c['committed_pct_budget']) ? ' (' . $c['committed_pct_budget'] . '% of budget)' : '';
        $ip = isset($c['invoiced_pct_budget']) ? ' (' . $c['invoiced_pct_budget'] . '% of budget)' : '';
        $totLine = 'Total spend: ' . $fmtEur($c['total_used_eur']);
        if ($c['total_spend_pct_budget'] !== null) {
            $totLine .= '  (' . $c['total_spend_pct_budget'] . '% of budget)';
        }
        if (isset($c['mbr_pct_ahead_schedule']) && is_numeric($c['mbr_pct_ahead_schedule'])) {
            $totLine .= '  vs pace: ' . (($c['mbr_pct_ahead_schedule'] >= 0) ? '+' : '') . $c['mbr_pct_ahead_schedule'] . '%';
        }
        $lines = [
            'Planned:     ' . $fmtEur($c['planned_eur']) . $pp,
            'Committed:   ' . $fmtEur($c['committed_eur']) . $cp . '  (Allocated + Executed)',
            'Invoiced:    ' . $fmtEur($c['invoiced_eur']) . $ip,
            $totLine,
            'Budget:      ' . $fmtEur($c['budget_eur']),
            'Remaining:   ' . $fmtEur($c['remaining_eur']),
            '',
            'Top partners (vendor / external vendor):',
        ];
        $first = true;
        foreach ($lines as $line) {
            if (!$first) {
                $body->createBreak();
            }
            $first = false;
            $body->createTextRun(mbr_pptx_safe_text($line))->getFont()->setSize(13)->setColor(new Color('FF334155'));
        }
        if (empty($c['partners'])) {
            $body->createBreak();
            $body->createTextRun('No partner data')->getFont()->setSize(12)->setItalic(true)->setColor(new Color('FF94A3B8'));
        } else {
            $n = 0;
            foreach ($c['partners'] as $p) {
                $n++;
                $partnerLabel = isset($p['partner']) ? (string) $p['partner'] : '';
                $totalEur = isset($p['total_eur']) ? $p['total_eur'] : 0;
                $body->createBreak();
                $body->createTextRun(
                    mbr_pptx_safe_text($n . '. ' . $partnerLabel . '  ' . $fmtEur($totalEur))
                )->getFont()->setSize(12)->setColor(new Color('FF0F172A'));
            }
        }
    }

    $writer = IOFactory::createWriter($present, 'PowerPoint2007');
    $tmpBase = sys_get_temp_dir();
    if (is_string($tmpBase) && $tmpBase !== '' && is_dir($tmpBase) && is_writable($tmpBase)) {
        $writer->setUseDiskCaching(true, $tmpBase);
    }

    $tmpFile = @tempnam($tmpBase ?: __DIR__, 'mbrpptx');
    if ($tmpFile === false) {
        throw new RuntimeException('Could not create temp file (tempnam failed)');
    }
    $pptxPath = $tmpFile . '.pptx';
    if (!@rename($tmpFile, $pptxPath)) {
        $pptxPath = $tmpFile;
    }

    $writer->save($pptxPath);

    if (!is_readable($pptxPath) || filesize($pptxPath) === 0) {
        @unlink($pptxPath);
        throw new RuntimeException('Writer produced an empty or unreadable file');
    }

    $fh = @fopen($pptxPath, 'rb');
    $magic = $fh ? fread($fh, 4) : '';
    if ($fh) {
        fclose($fh);
    }
    // .pptx is a ZIP archive; valid files start with "PK".
    if (strlen($magic) < 2 || substr($magic, 0, 2) !== 'PK') {
        @unlink($pptxPath);
        throw new RuntimeException('Generated file is not a valid ZIP/pptx (missing PK header)');
    }

    // Avoid gzip double-encoding the binary response; flush any buffered junk before headers.
    if (function_exists('ini_set')) {
        @ini_set('zlib.output_compression', '0');
    }
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . (string) filesize($pptxPath));
    header('X-Accel-Buffering: no');

    readfile($pptxPath);
    @unlink($pptxPath);
    exit;
} catch (\Throwable $e) {
    if (isset($pptxPath) && is_string($pptxPath) && is_file($pptxPath)) {
        @unlink($pptxPath);
    }
    $trace = $e->getTraceAsString();
    if (strlen($trace) > 8000) {
        $trace = substr($trace, 0, 8000) . "\n…(truncated)";
    }
    error_log('mbr_export_pptx: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    error_log('mbr_export_pptx trace: ' . $trace);

    $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    $wantDebug = $isAdmin && isset($_GET['debug']) && $_GET['debug'] === '1';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo mbr_pptx_format_failure($e, $wantDebug);
    if (!$wantDebug && $isAdmin) {
        echo "\n\nTip for admins: add &debug=1 to this URL to see the exact PHP error.";
    }
}
exit;
