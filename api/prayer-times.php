<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

const MASJID_ID = '5AvP54KX';
const WIDGET_URL = 'https://timing.athanplus.com/masjid/widgets/embed?theme=1&masjid_id=' . MASJID_ID;
const CACHE_TTL = 300;

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeTime(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim(strip_tags($value));
}

function fetchWidgetHtml(): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init(WIDGET_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'DurhamIslamicCentre/1.0',
        ]);
        $html = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($html !== false && $code >= 200 && $code < 300) {
            return $html;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header' => "User-Agent: DurhamIslamicCentre/1.0\r\n",
        ],
    ]);
    $html = @file_get_contents(WIDGET_URL, false, $context);
    return $html === false ? null : $html;
}

function parsePrayerDay(DOMXPath $xpath, int $index): ?array
{
    $tableDiv = $xpath->query('//*[@id="table_div_' . $index . '"]')->item(0);
    if (!$tableDiv instanceof DOMElement) {
        return null;
    }

    $dateNode = $xpath->query('//*[@id="top_' . $index . '"]//h2')->item(0);
    $hijriNode = $xpath->query('//*[@id="top_' . $index . '"]//p')->item(0);
    $date = $dateNode ? normalizeTime($dateNode->textContent) : '';
    $hijri = $hijriNode ? normalizeTime($hijriNode->textContent) : '';

    $timings = [];
    $rows = $xpath->query('.//table[contains(@class, "full-table-sec")]/tr', $tableDiv);
    foreach ($rows as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }

        $cells = $row->getElementsByTagName('td');
        if ($cells->length === 0) {
            continue;
        }

        $name = normalizeTime($cells->item(0)->textContent);
        if ($name === '' || stripos($name, 'first name') !== false) {
            continue;
        }

        if ($cells->length >= 3) {
            $starts = normalizeTime($cells->item(1)->textContent);
            $iqamah = normalizeTime($cells->item(2)->textContent);
            $timings[$name] = [
                'starts' => $starts,
                'iqamah' => $iqamah !== '' ? $iqamah : $starts,
            ];
            continue;
        }

        if ($cells->length === 2) {
            $time = normalizeTime($cells->item(1)->textContent);
            $timings[$name] = [
                'starts' => $time,
                'iqamah' => $time,
            ];
        }
    }

    $jumuah = [];
    $jumuahItems = $xpath->query('.//ul[contains(@class, "testing-sec")]/li', $tableDiv);
    foreach ($jumuahItems as $item) {
        if (!$item instanceof DOMElement) {
            continue;
        }
        $timeNode = $xpath->query('./b', $item)->item(0);
        $labelNode = $xpath->query('./p', $item)->item(0);
        $time = $timeNode ? normalizeTime($timeNode->textContent) : '';
        $label = $labelNode ? normalizeTime($labelNode->textContent) : 'Jumuah';
        if ($time !== '') {
            $jumuah[] = ['label' => $label, 'time' => $time];
        }
    }

    if ($timings === []) {
        return null;
    }

    return [
        'date' => $date,
        'hijri' => $hijri,
        'timings' => $timings,
        'jumuah' => $jumuah,
    ];
}

function parsePrayerWidget(string $html): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) {
        throw new RuntimeException('Unable to parse Masjidal widget HTML.');
    }

    $xpath = new DOMXPath($dom);
    $days = [];
    for ($index = 0; $index < 7; $index++) {
        $day = parsePrayerDay($xpath, $index);
        if ($day === null) {
            break;
        }
        $days[] = $day;
    }

    if ($days === []) {
        throw new RuntimeException('No prayer rows were parsed from Masjidal.');
    }

    $today = $days[0];

    return [
        'source' => 'masjidal',
        'masjid_id' => MASJID_ID,
        'widget_url' => WIDGET_URL,
        'date' => $today['date'],
        'hijri' => $today['hijri'],
        'timings' => $today['timings'],
        'jumuah' => $today['jumuah'],
        'days' => $days,
        'updated_at' => gmdate('c'),
    ];
}

$cacheFile = sys_get_temp_dir() . '/dic-prayer-' . MASJID_ID . '.json';

if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    readfile($cacheFile);
    exit;
}

$html = fetchWidgetHtml();
if ($html === null) {
    if (is_readable($cacheFile)) {
        readfile($cacheFile);
        exit;
    }
    respond(502, ['error' => 'Unable to fetch Masjidal prayer times.']);
}

try {
    $payload = parsePrayerWidget($html);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($cacheFile, $json);
    }
    echo $json;
} catch (Throwable $error) {
    if (is_readable($cacheFile)) {
        readfile($cacheFile);
        exit;
    }
    respond(502, ['error' => $error->getMessage()]);
}
