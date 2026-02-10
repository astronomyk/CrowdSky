<?php
/**
 * FITS header parsing and chunk key computation.
 *
 * FITS headers are 80-byte ASCII keyword records arranged in 2880-byte blocks.
 * We only need a handful of keywords from the primary header.
 */

/**
 * Parse selected keywords from a FITS file header.
 *
 * Reads 2880-byte blocks until END is found (or 10 blocks max).
 * Returns associative array with keys: DATE-OBS, OBJECT, EXPTIME, RA, DEC.
 *
 * @param string $filePath Path to a .fit/.fits file (local temp path).
 * @return array|false  Parsed header values, or false on failure.
 */
function parseFitsHeader(string $filePath)
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        return false;
    }

    // Validate FITS magic: first 30 bytes must be "SIMPLE  =                    T"
    $magic = fread($fh, 30);
    if ($magic !== 'SIMPLE  =                    T') {
        fclose($fh);
        return false;
    }
    fseek($fh, 0);

    $keywords = [
        'DATE-OBS' => null,
        'OBJECT'   => null,
        'EXPTIME'  => null,
        'RA'       => null,
        'DEC'      => null,
    ];

    $maxBlocks = 10;
    $found_end = false;

    for ($block = 0; $block < $maxBlocks && !$found_end; $block++) {
        $data = fread($fh, 2880);
        if ($data === false || strlen($data) < 2880) {
            break;
        }

        // Each block has 36 cards of 80 bytes
        for ($i = 0; $i < 36; $i++) {
            $card = substr($data, $i * 80, 80);
            $key = trim(substr($card, 0, 8));

            if ($key === 'END') {
                $found_end = true;
                break;
            }

            if (!array_key_exists($key, $keywords)) {
                continue;
            }

            // Value starts after "= " at position 10, up to position 80
            // (or up to "/" comment delimiter)
            $valueStr = substr($card, 10, 70);

            // Strip inline comment (but not inside quoted strings)
            if ($valueStr[0] === "'") {
                // String value: find closing quote
                $end = strpos($valueStr, "'", 1);
                if ($end !== false) {
                    $keywords[$key] = trim(substr($valueStr, 1, $end - 1));
                }
            } else {
                // Numeric or other value
                $slashPos = strpos($valueStr, '/');
                if ($slashPos !== false) {
                    $valueStr = substr($valueStr, 0, $slashPos);
                }
                $valueStr = trim($valueStr);
                if (is_numeric($valueStr)) {
                    $keywords[$key] = floatval($valueStr);
                } else {
                    $keywords[$key] = $valueStr;
                }
            }
        }
    }

    fclose($fh);
    return $keywords;
}

/**
 * Compute a chunk key from a DATE-OBS string, RA, and DEC.
 *
 * Format: YYYYMMDD.CC_RRR.R_sDD.D
 *   CC   = floor(seconds_since_UTC_midnight / 900)  (0..95)
 *   RRR.R = RA rounded to 1 decimal place
 *   sDD.D = signed DEC rounded to 1 decimal place
 *
 * @param string     $dateObs  ISO datetime, e.g. "2025-01-15T19:30:00"
 * @param float|null $ra       Right ascension in degrees (0..360)
 * @param float|null $dec      Declination in degrees (-90..+90)
 * @return string|null Chunk key or null if DATE-OBS is unparseable.
 */
function computeChunkKey(string $dateObs, ?float $ra = null, ?float $dec = null): ?string
{
    $ts = strtotime($dateObs);
    if ($ts === false) {
        return null;
    }

    $ymd = gmdate('Ymd', $ts);
    $midnight = gmmktime(0, 0, 0, (int)gmdate('n', $ts), (int)gmdate('j', $ts), (int)gmdate('Y', $ts));
    $secondsSinceMidnight = $ts - $midnight;
    $chunkIndex = intdiv($secondsSinceMidnight, 900);

    $key = sprintf('%s.%02d', $ymd, $chunkIndex);

    if ($ra !== null && $dec !== null) {
        $raStr = sprintf('%.1f', round($ra, 1));
        $sign = $dec >= 0 ? '+' : '-';
        $decStr = sprintf('%.1f', abs(round($dec, 1)));
        $key .= sprintf('_%s_%s%s', $raStr, $sign, $decStr);
    }

    return $key;
}
