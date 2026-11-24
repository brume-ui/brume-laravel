<?php

/**
 * Evolutionary search for inputs that make HtmlSanitizer expensive (fix round 4). Not a test:
 * the file name does not end in Test.php, so Pest never loads it. Among inputs the default limits
 * ACCEPT it looks for the worst
 *   mem   peak memory of one clean(), in MB above the warmed-up baseline
 *   time  wall time of one clean(), in ms
 *   amp   output bytes ÷ input bytes
 * and it records, apart, the slowest and hungriest REJECTED inputs, every input that crashed or
 * threw anything but HtmlRejected, and every accepted input whose output was larger than the
 * projection or the runtime charge said (an under-estimate: must stay empty).
 *
 * Every input is evaluated in a child process with memory_limit=128M, so a fatal error is a
 * finding, not the end of the search. One child at a time: run it niced, one objective after the
 * other, never several at once (the numbers are wall times).
 *
 *   nice -n 15 php tests/Feature/Html/resource-search.php <mem|time|amp> <evaluations> <seed> <out.json> [php -d flags…]
 *   nice -n 15 php tests/Feature/Html/resource-search.php replay <out.json>     measure the kept inputs again, best of 3
 *
 * e.g. `… time 10000 1 /tmp/time.json -d pcre.jit=0` searches without PCRE's JIT. With
 * BRUME_SEARCH_SECONDS=500 in the environment a run pauses after that long (exit code 3) and the
 * same command line continues it.
 */

use Brume\Html\HtmlRejected;
use Brume\Html\HtmlSanitizer;

require dirname(__DIR__, 3).'/vendor/autoload.php';

const MAX_INPUT = 300_000;

/** str_repeat that never builds more than the input limit. */
function rep(string $unit, int $times): string
{
    return str_repeat($unit, max(0, min($times, intdiv(MAX_INPUT, max(1, strlen($unit))) + 1)));
}

/** @return array<string, Closure(int, int, int): string> gene kind => f(repeat, attribute size, variant) */
function genes(): array
{
    $formatting = ['b', 'i', 'u', 's', 'em', 'strong', 'a', 'sup', 'sub', 'span', 'label'];
    $blocks = ['div', 'p', 'h1', 'h3', 'blockquote', 'article', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'caption', 'tbody'];
    $declarations = [
        'color:red', 'color:tan', 'background:tan url(a) no-repeat', 'background-image:url(https://e.x/a b)', 'margin:0 0 0 0',
        'font-family:a,b,c', 'border:1px solid tan', 'top:0', 'box-shadow:0 0 0 0 red,0 0 0 0 red', 'flex:1 1 0',
        'width:calc(1px + 1px)', 'padding:.5em', 'position:fixed', 'x:y', 'gap:1px 1px', 'opacity:.5', 'text-align:center',
        'background-position:left left left left', 'outline:1px solid red', 'border-radius:1px 1px 1px 1px',
    ];
    $value = function (int $a, int $v) use ($declarations): array {
        $a = max(1, min($a, 9000));

        return match ($v % 14) {
            0 => ['class', rep('a', $a)],
            1 => ['class', substr(implode(' ', array_map(fn ($i) => 'c'.$i, range(1, max(1, intdiv($a, 4))))), 0, $a)],
            2 => ['style', substr(rep($declarations[$v % count($declarations)].';', intdiv($a, 6) + 1), 0, $a)],
            3 => ['style', substr(implode(';', array_map(fn ($i) => $declarations[($i * 7 + $v) % count($declarations)], range(1, intdiv($a, 8) + 1))), 0, $a)],
            4 => ['href', 'https://e.x/'.rep('a b&', intdiv($a, 4))],
            5 => ['title', rep('"', $a)],
            6 => ['title', rep('&amp;', intdiv($a, 5) + 1)],
            7 => ['id', rep('i', $a)],
            8 => ['style', 'background-image:url('.rep("\u{e9}", intdiv($a, 2)).')'],
            9 => ['style', 'font-family:'.substr(rep('a,', $a), 0, $a)],
            10 => ['class', substr(rep('a ', $a), 0, $a)],
            11 => ['src', '/'.rep('%', $a)],
            12 => ['style', substr(rep('box-shadow:'.rep('0 0 0 0 red,', 20).'0 0 0 0 red;', intdiv($a, 250) + 1), 0, $a)],
            default => ['data-th', rep('x', $a)],
        };
    };
    $attr = function (int $a, int $v) use ($value): string {
        if ($a <= 0) {
            return '';
        }
        [$name, $val] = $value($a, $v);
        $quote = $v % 5 === 0 ? "'" : '"';

        return ' '.$name.'='.$quote.str_replace($quote, '', $val).$quote;
    };
    $f = fn (int $v) => $formatting[$v % count($formatting)];
    $b = fn (int $v) => $blocks[$v % count($blocks)];

    return [
        'text' => fn ($n, $a, $v) => rep(['x ', '&amp;', '&', '&nbsp;', '&#x26;', '< ', "\u{4e2d}", '&lt;p&gt;', "\n", '>'][$v % 10], $n),
        'flat' => fn ($n, $a, $v) => rep('<'.$b($v).$attr($a, $v).'>x</'.$b($v).'>', $n),
        'unclosed' => fn ($n, $a, $v) => rep('<'.$b($v).$attr($a, $v).'>x', $n),
        'void' => fn ($n, $a, $v) => rep(['<br>', '<hr>', '<img src="/a.png" alt="a">', '<input type="checkbox">', '<col>', '<img src=x>'][$v % 6], $n),
        'amp' => fn ($n, $a, $v) => '<'.$f($v).$attr($a, $v).'>'.rep('<'.$b($v >> 4).'>x</'.$b($v >> 4).'>', $n),
        'ampopen' => fn ($n, $a, $v) => implode('', array_map(fn ($i) => '<'.$f($v + $i).$attr($a, $v + $i).'>', range(0, $v % 9))).rep('<'.$b($v >> 4).'>x', $n),
        'open' => fn ($n, $a, $v) => rep('<'.$f($v).$attr($a, $v).'>', min($n, 250)),
        'openblock' => fn ($n, $a, $v) => rep('<'.$b($v).$attr($a, $v).'>', min($n, 250)),
        'close' => fn ($n, $a, $v) => rep('</'.($v % 2 ? $f($v) : $b($v)).'>', $n),
        'manyattrs' => fn ($n, $a, $v) => '<'.$b($v).' '.implode(' ', array_map(fn ($i) => 'a'.$i.($v % 2 ? '=1' : ''), range(1, max(1, min($n, 1200))))).'>x',
        'attrs' => fn ($n, $a, $v) => rep('<'.$b($v).$attr($a, $v).$attr($a, $v + 1).$attr($a, $v + 2).$attr($a, $v + 3).'>x', $n),
        'table' => fn ($n, $a, $v) => '<table'.$attr($a, $v).'><caption>c</caption>'.rep('<tr><td'.$attr($a, $v + 1).'>a</td><th>b</th></tr>', $n).'</table>',
        'badtable' => fn ($n, $a, $v) => '<table>'.rep(['<td>a', '<tr>x', '<tbody><b>', '<caption><p>c', '<col><colgroup>', '<table><tr>', '</td><div>x', '<tfoot><tr><td>'][$v % 8], $n),
        'list' => fn ($n, $a, $v) => rep(['<ul><li>a', '<ol><ol>', '<ul>x<ul>', '<li><b><ol>', '<ul><li>a</li></ul>', '</li><li>'][$v % 6], $n),
        'link' => fn ($n, $a, $v) => rep(['<a href="/x"'.$attr($a, $v).'>y</a>', '<a href=x><div>y</div>', '<a><a><a>', '<a href="mailto:a@b.c">m</a>', '<a href="/x"><table><tr><td>t</td></tr></table></a>', '<a><li>'][$v % 6], $n),
        'comment' => fn ($n, $a, $v) => rep(['<!---->', 'a<!---->', '<!--', '<!-- '.rep('x', $a % 200).' -->', '<!--[if x]>', '<![endif]-->', '<!--//--><![CDATA[//><!--x', '-->'][$v % 8], $n),
        'cdata' => fn ($n, $a, $v) => rep(['<![CDATA[', '<![CDATA[x]]>', ']]>', '<![CDATA[<a href=x>]]>', '<?x', '<?x?>', '?>', '<!DOCTYPE html>'][$v % 8], $n),
        'raw' => fn ($n, $a, $v) => rep(['<script>', '<script>a</script>', "<script>\na", '</script>', '<style>', '<style>a{}</style>', '<body>', '</body>', '<body x', '<html>', '<head>', '<title>', '<textarea>', '<plaintext>'][$v % 14], $n),
        'junk' => fn ($n, $a, $v) => rep(['<', '<<a', '<b x=a\'b>', '\'>', '">', '<p a="', '<p/', '&#', '&#x', '&a', "\x01", "\xC3", '<x>', '<x:y>', '<p =>', '= "'][$v % 16], $n),
        'iframe' => fn ($n, $a, $v) => rep('<iframe src="https://www.youtube.com/embed/abcdefghijk?'.rep('a', $a % 500).'" width="1" height="1" allowfullscreen></iframe>', $n),
        'deep' => fn ($n, $a, $v) => rep('<'.($v % 3 ? 'div' : 'span').$attr($a, $v).'>', min($n, 250)).'x',
        'nest' => fn ($n, $a, $v) => rep('<'.$f($v).'><'.$b($v >> 3).$attr($a, $v).'>', min($n, 120)).'x',
        'words' => fn ($n, $a, $v) => '<p'.$attr($a, $v).' '.rep(['x ', 'x/', 'x="1"', "x='>' ", 'x= ">" y '][$v % 5], min($n, 3000)).'>x',
    ];
}

/** @param  list<array{string, int, int, int}>  $genome */
function render(array $genome): string
{
    static $genes;
    $genes ??= genes();
    $html = '';
    foreach ($genome as [$kind, $n, $a, $v]) {
        $html .= $genes[$kind]($n, $a, $v);
        if (strlen($html) >= MAX_INPUT) {
            break;
        }
    }

    return substr($html, 0, MAX_INPUT);
}

function randomGene(): array
{
    static $kinds;
    $kinds ??= array_keys(genes());
    $n = [1, 2, 5, 20, 100, 500, 2000, 10000, 40000][mt_rand(0, 8)];
    $a = [0, 0, 0, 8, 60, 500, 4000, 8192, 8200][mt_rand(0, 8)];

    return [$kinds[mt_rand(0, count($kinds) - 1)], mt_rand(1, $n), $a === 0 ? 0 : mt_rand(1, $a), mt_rand(0, 1 << 16)];
}

/**
 * Known expensive families, so the search starts from them instead of having to find them again.
 *
 * @return list<list<array{string, int, int, int}>>
 */
function seeds(): array
{
    $seeds = [];
    // Round 3 findings: one formatting element with a big attribute (variant % 14 picks its kind:
    // 0 one long class, 1 many classes, 2/3 style declarations, 8 a long url(), 12 box-shadows),
    // reopened in n blocks; at, just under and far under the attribute limits.
    foreach ([0, 99, 2, 3, 8, 12, 4, 5] as $kind) {
        $variant = $kind + 14 * 11 * 16;
        foreach ([[1000, 9000], [1000, 8192], [140, 1000], [60, 2400], [30, 8000], [300, 200], [2000, 60]] as [$n, $a]) {
            $seeds[] = [['amp', $n, $a, $variant]];
        }
        $seeds[] = [['ampopen', 100, 1000, $variant]];
        $seeds[] = [['flat', 36, 8192, $variant]];
        $seeds[] = [['attrs', 20, 4000, $variant]];
        $seeds[] = [['nest', 60, 500, $variant]];
    }
    // Worst accepted inputs of the earlier searches: many links, flat and unclosed blocks, table
    // cells, void elements, formatting around attribute-heavy blocks.
    $seeds[] = [['link', 15043, 0, 37944]];
    $seeds[] = [['link', 17056, 0, 40729], ['void', 2816, 1, 48697]];
    $seeds[] = [['unclosed', 6877, 0, 34212], ['flat', 6307, 0, 43785], ['table', 18332, 24, 40444]];
    $seeds[] = [['flat', 17, 5764, 20176], ['void', 8141, 0, 42713], ['flat', 28332, 1189, 64349]];
    $seeds[] = [['open', 28, 0, 9751], ['open', 30, 0, 9751], ['attrs', 14, 0, 42315], ['attrs', 56, 0, 42316]];
    $seeds[] = [['amp', 5, 579, 16570], ['deep', 9, 0, 58862], ['amp', 16, 1158, 52351], ['amp', 32, 579, 52351]];
    $seeds[] = [['table', 2587, 2, 44581], ['close', 68, 241, 43187], ['table', 1990, 2, 44325]];
    $seeds[] = [['iframe', 1, 520, 30860], ['table', 778, 0, 28151], ['flat', 29498, 40, 52113], ['list', 6917, 4051, 21990]];
    $seeds[] = [['text', 300000, 0, 2]];
    $seeds[] = [['text', 60000, 0, 1]];
    $seeds[] = [['manyattrs', 1000, 0, 1], ['manyattrs', 1000, 0, 0]];
    $seeds[] = [['words', 1000, 0, 0]];
    $seeds[] = [['badtable', 23802, 64, 57870]];
    $seeds[] = [['comment', 40000, 0, 0]];

    return $seeds;
}

/** @param  list<list<array{string, int, int, int}>>  $population */
function mutate(array $genome, array $population): array
{
    for ($k = mt_rand(1, 3); $k > 0; $k--) {
        $i = mt_rand(0, max(0, count($genome) - 1));
        switch (mt_rand(0, 9)) {
            case 0: array_splice($genome, mt_rand(0, count($genome)), 0, [randomGene()]);
                break;
            case 1: if (count($genome) > 1) {
                array_splice($genome, $i, 1);
            }
                break;
            case 2: $genome[$i][1] = max(1, (int) ($genome[$i][1] * [0.5, 0.8, 0.95, 1.05, 1.3, 2, 4][mt_rand(0, 6)]));
                break;
            case 3: $genome[$i][2] = min(9000, max(0, (int) (($genome[$i][2] ?: 8) * [0, 0.5, 0.9, 1.1, 2, 8][mt_rand(0, 5)])));
                break;
            case 4: $genome[$i][3] = mt_rand(0, 1 << 16);
                break;
            case 5: array_splice($genome, $i, 0, [$genome[$i]]);
                break;
            case 6: $other = $population[mt_rand(0, count($population) - 1)];
                $genome = [...array_slice($genome, 0, mt_rand(0, count($genome))), ...array_slice($other, mt_rand(0, count($other)))];
                break;
            case 7: $genome[$i][3] += [1, -1, 16, 256][mt_rand(0, 3)];
                $genome[$i][3] = abs($genome[$i][3]);
                break;
            case 8: [$genome[$i], $genome[0]] = [$genome[0], $genome[$i]];
                break;
            default: $genome[] = randomGene();
        }
        if ($genome === []) {
            $genome = [randomGene()];
        }
        $genome = array_slice($genome, 0, 12);
    }

    return array_values($genome);
}

function evaluateBatch(string $batchFile): void
{
    $sanitizer = new HtmlSanitizer(['cache_path' => false]);
    $sanitizer->clean('<p><b>warm</b></p><table><tr><td style="color:red" class="a">x</td></tr></table><a href="/x">y</a><ul><li>z</li></ul>');
    $out = fopen($batchFile.'.out', 'w');
    foreach (unserialize(file_get_contents($batchFile)) as $index => $genome) {
        fwrite($out, "start $index\n");
        $html = render($genome);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $time = hrtime(true);
        try {
            $clean = $sanitizer->clean($html);
            $status = 'ok';
        } catch (HtmlRejected $e) {
            $clean = null;
            $status = 'rejected:'.$e->reason;
        } catch (Throwable $e) {
            $clean = null;
            $status = 'threw:'.$e::class.':'.substr($e->getMessage(), 0, 120);
        }
        $row = [$index, $status, round((hrtime(true) - $time) / 1e6, 1), round((memory_get_peak_usage() - $memory) / 1048576, 1), strlen($html), strlen((string) $clean)];
        if ($status === 'ok') {
            // After the measurement: the projection and the runtime charge must be upper bounds.
            $charged = $sanitizer->lastByteCount();
            $elements = preg_match_all('/<[a-z]/', (string) $clean);
            $stats = $sanitizer->treeStats($html);
            if (strlen((string) $clean) > $stats['bytes'] || strlen((string) $clean) > $charged || $elements > $stats['projected']) {
                $row[1] = sprintf('under:out=%d projected=%d charged=%d elements=%d projectedElements=%d', strlen((string) $clean), $stats['bytes'], $charged, $elements, $stats['projected']);
            }
        }
        fwrite($out, json_encode($row)."\n");
    }
    fclose($out);
}

/** @return list<array{int, string, float, float, int, int}|array{int, string}> one row per genome; crashed ones are ['crash', …] */
function runBatch(array $genomes, array $phpFlags, string $tmp): array
{
    $rows = [];
    $pending = $genomes;
    while ($pending !== []) {
        file_put_contents($tmp, serialize($pending));
        @unlink($tmp.'.out');
        exec(escapeshellarg(PHP_BINARY).' -d memory_limit=128M '.implode(' ', array_map('escapeshellarg', $phpFlags)).' '.escapeshellarg(__FILE__).' worker '.escapeshellarg($tmp).' 2>&1', $output, $exit);
        $started = null;
        $done = [];
        foreach (file($tmp.'.out', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'start ')) {
                $started = (int) substr($line, 6);
            } else {
                $row = json_decode($line, true);
                $done[$row[0]] = true;
                $rows[] = [$pending[$row[0]], ...array_slice($row, 1)];
            }
        }
        if ($exit === 0) {
            break;
        }
        if ($started === null) {
            throw new RuntimeException('The worker failed before evaluating anything: '.implode("\n", $output));
        }
        // The worker died on $started (a fatal error): record it and go on with the rest.
        if (! isset($done[$started])) {
            $rows[] = [$pending[$started], 'crash:'.substr(implode(' ', $output), 0, 160), 0.0, 0.0, 0, 0];
            $done[$started] = true;
        }
        $pending = array_values(array_diff_key($pending, $done));
        unset($output);
    }

    return $rows;
}

if (($argv[1] ?? '') === 'worker') {
    evaluateBatch($argv[2]);
    exit(0);
}

if (($argv[1] ?? '') === 'replay') {
    $result = json_decode(file_get_contents($argv[2]), true);
    $flags = array_slice($argv, 3);
    foreach (['top', 'rejected', 'crashes'] as $list) {
        foreach ($result[$list] ?? [] as $entry) {
            $best = null;
            for ($i = 0; $i < 3; $i++) {
                $row = runBatch([$entry['genome']], $flags, sys_get_temp_dir().'/brume-search-replay-'.getmypid())[0];
                $best = $best === null || $row[2] < $best[2] ? $row : $best;
            }
            printf("%-8s %-20s %8.1f ms %6.1f MB  in=%d out=%d  %s\n", $list, $best[1], $best[2], $best[3], $best[4], $best[5], json_encode($entry['genome']));
        }
    }
    exit(0);
}

[$objective, $evaluations, $seed, $outFile] = [$argv[1] ?? 'time', (int) ($argv[2] ?? 1000), (int) ($argv[3] ?? 1), $argv[4] ?? 'search.json'];
$phpFlags = array_slice($argv, 5);
mt_srand($seed);
$tmp = sys_get_temp_dir().'/brume-search-'.getmypid();
$fitness = fn (array $row): float => $row[1] !== 'ok' ? -1.0 : match ($objective) {
    'mem' => $row[3],
    'time' => $row[2],
    default => $row[5] / max(1, $row[4]),
};
$population = [];
$rejected = [];
$crashes = [];
$seen = [];
$count = 0;
$statuses = [];
// The first batch: the known families (round 3 findings, earlier searches), then random genomes.
$batch = [...seeds(), ...array_map(fn () => array_map(fn () => randomGene(), range(1, mt_rand(1, 4))), range(1, 150))];
$batch = array_values(array_filter($batch, function (array $genome) use (&$seen): bool {
    $key = md5(render($genome));
    $fresh = ! isset($seen[$key]);
    $seen[$key] = true;

    return $fresh;
}));
// A run stops by itself after BRUME_SEARCH_SECONDS (exit code 3) and the same command line
// continues it from <out.json>.state, so a long search can be run in short foreground pieces.
$stateFile = $outFile.'.state';
$deadline = microtime(true) + (float) (getenv('BRUME_SEARCH_SECONDS') ?: INF);
if (is_file($stateFile)) {
    [$count, $population, $rejected, $crashes, $statuses, $batch, $seen] = unserialize(file_get_contents($stateFile));
    mt_srand($seed + $count);
    fprintf(STDERR, "[%s seed %d] resuming at %d evaluations\n", $objective, $seed, $count);
}
while ($count < $evaluations) {
    if (microtime(true) > $deadline) {
        file_put_contents($stateFile, serialize([$count, $population, $rejected, $crashes, $statuses, $batch, $seen]));
        fprintf(STDERR, "[%s seed %d] paused at %d of %d evaluations; run the same command to continue\n", $objective, $seed, $count, $evaluations);
        exit(3);
    }
    // Never more than the budget asked for.
    $batch = array_slice($batch, 0, $evaluations - $count);
    foreach (runBatch($batch, $phpFlags, $tmp) as $row) {
        $count++;
        $statuses[strtok($row[1], ':')] = ($statuses[strtok($row[1], ':')] ?? 0) + 1;
        if (str_starts_with($row[1], 'crash') || str_starts_with($row[1], 'threw') || str_starts_with($row[1], 'under')) {
            $crashes[] = $row;
        } elseif ($row[1] !== 'ok') {
            $rejected[] = $row;
        } else {
            $population[] = $row;
        }
    }
    usort($population, fn ($x, $y) => $fitness($y) <=> $fitness($x));
    $population = array_slice($population, 0, 60);
    // Slowest and hungriest rejections, kept apart.
    usort($rejected, fn ($x, $y) => max($y[2] / 300, $y[3] / 20) <=> max($x[2] / 300, $x[3] / 20));
    $rejected = array_slice($rejected, 0, 10);
    $crashes = array_slice($crashes, 0, 20);
    $pool = array_column($population, 0) ?: [[randomGene()]];
    $batch = [];
    while (count($batch) < 200) {
        $parent = mt_rand(0, 9) === 0 ? [randomGene()] : $pool[min(mt_rand(0, count($pool) - 1), mt_rand(0, count($pool) - 1))];
        $child = mutate($parent, $pool);
        // By what it renders to: different genomes can give the same HTML (everything past the
        // input limit is cut off), and the sanitiser would answer a repeat from its memo.
        $key = md5(render($child));
        if (! isset($seen[$key])) {
            $seen[$key] = true;
            $batch[] = $child;
        }
    }
    $entry = fn (array $row) => ['status' => $row[1], 'ms' => $row[2], 'mb' => $row[3], 'in' => $row[4], 'out' => $row[5], 'genome' => $row[0]];
    file_put_contents($outFile, json_encode([
        'objective' => $objective, 'evaluations' => $count, 'seed' => $seed, 'flags' => $phpFlags, 'statuses' => $statuses,
        'top' => array_map($entry, array_slice($population, 0, 10)),
        'rejected' => array_map($entry, $rejected),
        'crashes' => array_map($entry, $crashes),
    ]));
    if ($count % 2000 < 200 && $population !== []) {
        $best = $population[0];
        fprintf(STDERR, "[%s seed %d] %d evaluations; best %.1f (%s, %.1f ms, %.1f MB, in %d, out %d); worst rejected %.1f ms %.1f MB; crashes/under-estimates %d; %s\n", $objective, $seed, $count, $fitness($best), $best[1], $best[2], $best[3], $best[4], $best[5], $rejected[0][2] ?? 0, $rejected[0][3] ?? 0, count($crashes), json_encode($statuses));
    }
}
@unlink($tmp);
@unlink($tmp.'.out');
@unlink($stateFile);
fprintf(STDERR, "[%s seed %d] done: %d evaluations %s\n", $objective, $seed, $count, json_encode($statuses));
