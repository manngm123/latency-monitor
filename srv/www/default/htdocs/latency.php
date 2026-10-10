<?php

date_default_timezone_set('Europe/Berlin');


/*
 * Konfiguration
 */

$LOGDIR = '/var/lib/latency-monitor';

$TODAY = date('Y-m-d');


/*
 * Vorhandene Tagesdateien ermitteln
 */

$availableDates = [];

foreach (glob($LOGDIR . '/latency-????-??-??.csv') ?: [] as $file) {

    if (
        preg_match(
            '/latency-(\d{4}-\d{2}-\d{2})\.csv$/',
            basename($file),
            $matches
        )
    ) {

        $availableDates[] = $matches[1];
    }
}

sort($availableDates);


/*
 * Gewünschtes Datum bestimmen
 */

$selectedDate = $_GET['date'] ?? $TODAY;


/*
 * Datum validieren
 */

$dateObject = DateTime::createFromFormat(
    '!Y-m-d',
    $selectedDate
);

$dateValid =
    $dateObject !== false &&
    $dateObject->format('Y-m-d') === $selectedDate;


if (!$dateValid) {

    $selectedDate = $TODAY;
}


/*
 * Falls heute noch keine Datei existiert,
 * die neueste vorhandene Datei anzeigen.
 */

$selectedFile = sprintf(
    '%s/latency-%s.csv',
    $LOGDIR,
    $selectedDate
);

if (
    !is_readable($selectedFile) &&
    $selectedDate === $TODAY &&
    !empty($availableDates)
) {

    $selectedDate = end($availableDates);

    $selectedFile = sprintf(
        '%s/latency-%s.csv',
        $LOGDIR,
        $selectedDate
    );
}


/*
 * Position in den verfügbaren Tagen
 */

$currentIndex = array_search(
    $selectedDate,
    $availableDates,
    true
);

$previousDate = null;
$nextDate = null;

if ($currentIndex !== false) {

    if ($currentIndex > 0) {

        $previousDate =
            $availableDates[$currentIndex - 1];
    }

    if (
        $currentIndex <
        count($availableDates) - 1
    ) {

        $nextDate =
            $availableDates[$currentIndex + 1];
    }
}


/*
 * CSV einlesen
 */

$rows = [];

if (
    is_readable($selectedFile) &&
    ($fh = fopen($selectedFile, 'r'))
) {

    /*
     * Header
     */
    fgetcsv($fh);


    while (($r = fgetcsv($fh)) !== false) {

        if (count($r) < 10) {
            continue;
        }


        $ts = strtotime($r[0]);

        if ($ts === false) {
            continue;
        }


        $rows[] = [

            'ts'      => $ts,
            'time'    => $r[0],
            'target'  => $r[1],
            'address' => $r[2],

            'sent' => (int)$r[3],
            'recv' => (int)$r[4],

            /*
             * Loss zusätzlich nochmals aus
             * sent/received ableiten.
             *
             * Dadurch werden auch alte CSV-Dateien
             * mit eventuell falschem loss_pct
             * korrekt dargestellt.
             */
            'loss' =>
                ((int)$r[3] > 0)
                    ? round(
                        (
                            ((int)$r[3] - (int)$r[4])
                            /
                            (int)$r[3]
                        ) * 100,
                        1
                    )
                    : 100.0,

            'min' =>
                $r[6] !== ''
                    ? (float)$r[6]
                    : null,

            'avg' =>
                $r[7] !== ''
                    ? (float)$r[7]
                    : null,

            'max' =>
                $r[8] !== ''
                    ? (float)$r[8]
                    : null,

            'mdev' =>
                $r[9] !== ''
                    ? (float)$r[9]
                    : null
        ];
    }

    fclose($fh);
}


/*
 * Nach Ziel gruppieren
 */

$byTarget = [];

foreach ($rows as $row) {

    if (!isset($byTarget[$row['target']])) {

        $byTarget[$row['target']] = [];
    }

    $byTarget[$row['target']][] =
        $row;
}


/*
 * Farben
 */

$colors = [
    '#2563eb',
    '#dc2626',
    '#16a34a',
    '#d97706',
    '#7c3aed'
];


/*
 * Datasets und Zusammenfassung
 */

$latencyDatasets = [];
$lossDatasets = [];
$summary = [];

$ci = 0;


foreach ($byTarget as $target => $list) {


    $avgs = array_values(
        array_filter(
            array_column($list, 'avg'),
            fn($v) => $v !== null
        )
    );


    $mins = array_values(
        array_filter(
            array_column($list, 'min'),
            fn($v) => $v !== null
        )
    );


    $maxs = array_values(
        array_filter(
            array_column($list, 'max'),
            fn($v) => $v !== null
        )
    );


    $mdevs = array_values(
        array_filter(
            array_column($list, 'mdev'),
            fn($v) => $v !== null
        )
    );


    $losses =
        array_column(
            $list,
            'loss'
        );


    $last = end($list);


    /*
     * Zusammenfassung
     */

    $summary[$target] = [

        'address' =>
            $last['address'],

        'count' =>
            count($list),

        'current' =>
            $last['avg'],

        'current_loss' =>
            $last['loss'],

        'avg' =>
            $avgs
                ? round(
                    array_sum($avgs)
                    /
                    count($avgs),
                    2
                )
                : null,

        'min' =>
            $mins
                ? min($mins)
                : null,

        'max' =>
            $maxs
                ? max($maxs)
                : null,

        'mdev' =>
            $mdevs
                ? round(
                    array_sum($mdevs)
                    /
                    count($mdevs),
                    2
                )
                : null,

        'loss' =>
            $losses
                ? round(
                    array_sum($losses)
                    /
                    count($losses),
                    2
                )
                : null,

        'last_time' =>
            $last['time']
    ];


    /*
     * Diagrammpunkte
     */

    $latencyPoints = [];
    $lossPoints = [];


    foreach ($list as $item) {


        $latencyPoints[] = [

            'x' =>
                $item['ts'] * 1000,

            'y' =>
                $item['avg']
        ];


        $lossPoints[] = [

            'x' =>
                $item['ts'] * 1000,

            'y' =>
                $item['loss']
        ];
    }


    $color =
        $colors[
            $ci++
            %
            count($colors)
        ];


    $latencyDatasets[] = [

        'label' =>
            $target,

        'data' =>
            $latencyPoints,

        'borderColor' =>
            $color,

        'backgroundColor' =>
            $color,

        'borderWidth' =>
            1.5,

        'pointRadius' =>
            0,

        'pointHoverRadius' =>
            4,

        'tension' =>
            0.15,

        'spanGaps' =>
            false
    ];


    $lossDatasets[] = [

        'label' =>
            $target,

        'data' =>
            $lossPoints,

        'borderColor' =>
            $color,

        'backgroundColor' =>
            $color,

        'borderWidth' =>
            1.5,

        'pointRadius' =>
            0,

        'pointHoverRadius' =>
            4,

        'tension' =>
            0,

        'spanGaps' =>
            false
    ];
}


/*
 * Tabelle
 *
 * Alle Messungen des Tages anzeigen.
 */

$recent = $rows;


usort(
    $recent,
    fn($a, $b) =>
        $b['ts'] <=> $a['ts']
);


/*
 * Statusanzeige
 */

function statusClass(
    $target,
    $latency,
    $loss
) {

    if ($latency === null) {

        return 'status-bad';
    }


    /*
     * Loss hat Vorrang
     */

    if ($loss >= 5) {

        return 'status-bad';
    }


    if ($loss > 0) {

        return 'status-warn';
    }


    /*
     * FritzBox
     */

    if ($target === 'FritzBox') {


        if ($latency < 1) {

            return 'status-good';
        }


        if ($latency < 5) {

            return 'status-warn';
        }


        return 'status-bad';
    }


    /*
     * Internetziele
     */

    if ($latency < 25) {

        return 'status-good';
    }


    if ($latency < 50) {

        return 'status-warn';
    }


    return 'status-bad';
}


/*
 * Deutsches Datum
 */

$selectedDateFormatted =
    date(
        'd.m.Y',
        strtotime($selectedDate)
    );


/*
 * Nur die aktuelle Tagesseite
 * automatisch aktualisieren.
 */

$autoRefresh =
    $selectedDate === $TODAY;

?>
<!doctype html>

<html lang="de">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<?php if ($autoRefresh): ?>

<meta
    http-equiv="refresh"
    content="180"
>

<?php endif; ?>


<title>
Latenz-Monitoring
</title>


<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4">
</script>


<style>

body {

    font-family:
        system-ui,
        sans-serif;

    margin: 1.5rem;

    background: #f8fafc;

    color: #1e293b;
}


h1 {

    font-size: 1.5rem;

    margin-bottom: .4rem;
}


h2 {

    font-size: 1.05rem;

    margin-top: 0;
}


.subtitle {

    color: #64748b;

    font-size: .85rem;

    margin-bottom: 1rem;
}


/*
 * Tagesnavigation
 */

.day-nav {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: .5rem;

    margin:
        1rem 0;
}


.day-nav a,
.day-nav button {

    padding:
        .45rem
        .8rem;

    border:
        1px solid
        #cbd5e1;

    border-radius:
        6px;

    text-decoration: none;

    color: #334155;

    background: #fff;

    font-size: .85rem;
}


.day-nav a:hover {

    background: #f1f5f9;
}


.day-nav .disabled {

    opacity: .4;

    pointer-events: none;
}


.day-nav .today {

    background: #2563eb;

    color: #fff;

    border-color: #2563eb;
}


.date-select {

    padding:
        .42rem
        .6rem;

    border:
        1px solid
        #cbd5e1;

    border-radius:
        6px;

    background: #fff;
}


.current-day {

    font-size: 1.15rem;

    font-weight: 650;

    margin:
        .7rem 0;
}


/*
 * Statuskarten
 */

.cards {

    display: flex;

    flex-wrap: wrap;

    gap: .8rem;

    margin:
        1rem 0;
}


.card {

    background: #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius:
        10px;

    padding:
        .9rem
        1rem;

    min-width:
        230px;

    flex: 1;
}


.card-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom:
        .5rem;
}


.card-title {

    font-weight:
        650;
}


.address {

    font-size:
        .75rem;

    color:
        #64748b;
}


.current {

    font-size:
        1.7rem;

    font-weight:
        650;

    margin:
        .4rem 0;
}


.current small {

    font-size:
        .8rem;

    font-weight:
        normal;

    color:
        #64748b;
}


.stats {

    font-size:
        .8rem;

    color:
        #475569;

    line-height:
        1.6;
}


/*
 * Status
 */

.status-dot {

    width:
        12px;

    height:
        12px;

    border-radius:
        50%;
}


.status-good {

    background:
        #16a34a;
}


.status-warn {

    background:
        #f59e0b;
}


.status-bad {

    background:
        #dc2626;
}


/*
 * Diagramme
 */

.wrap {

    background:
        #fff;

    border:
        1px solid
        #e2e8f0;

    border-radius:
        10px;

    padding:
        1rem;

    margin:
        1rem 0;
}


.chart-container {

    position:
        relative;

    height:
        330px;
}


/*
 * Tabelle
 */

.table-wrap {

    overflow-x:
        auto;
}


table {

    border-collapse:
        collapse;

    width:
        100%;

    background:
        #fff;

    font-size:
        .82rem;
}


th,
td {

    border-bottom:
        1px solid
        #e2e8f0;

    padding:
        .45rem
        .6rem;

    text-align:
        right;

    white-space:
        nowrap;
}


th {

    background:
        #f8fafc;

    font-weight:
        600;

    position:
        sticky;

    top:
        0;
}


th:first-child,
td:first-child,
th:nth-child(2),
td:nth-child(2),
th:nth-child(3),
td:nth-child(3) {

    text-align:
        left;
}


.loss-good {

    color:
        #16a34a;
}


.loss-warn {

    color:
        #d97706;

    font-weight:
        600;
}


.loss-bad {

    color:
        #dc2626;

    font-weight:
        700;
}


.no-data {

    padding:
        2rem;

    text-align:
        center;

    color:
        #64748b;
}


.footer {

    margin-top:
        1rem;

    color:
        #64748b;

    font-size:
        .75rem;
}

</style>

</head>


<body>


<h1>
Latenz-Monitoring
</h1>


<div class="subtitle">

    FritzBox, Cloudflare, Heise und Google
    · Messung alle 3 Minuten

    <?php if ($autoRefresh): ?>

        · automatische Aktualisierung alle 3 Minuten

    <?php endif; ?>

</div>



<div class="day-nav">


<?php if ($previousDate): ?>

<a href="?date=<?=htmlspecialchars($previousDate)?>">

    ←
    <?=htmlspecialchars(
        date(
            'd.m.',
            strtotime($previousDate)
        )
    )?>

</a>

<?php else: ?>

<span class="disabled">

    ← vorheriger Tag

</span>

<?php endif; ?>



<a
    href="?date=<?=htmlspecialchars($TODAY)?>"
    class="today"
>

    Heute

</a>



<?php if ($nextDate): ?>

<a href="?date=<?=htmlspecialchars($nextDate)?>">

    <?=htmlspecialchars(
        date(
            'd.m.',
            strtotime($nextDate)
        )
    )?>
    →

</a>

<?php else: ?>

<span class="disabled">

    nächster Tag →

</span>

<?php endif; ?>



<form
    method="get"
    style="display:inline"
>

<input
    type="date"
    name="date"
    class="date-select"
    value="<?=htmlspecialchars($selectedDate)?>"
    max="<?=htmlspecialchars($TODAY)?>"
    onchange="this.form.submit()"
>

</form>

</div>



<div class="current-day">

    <?=htmlspecialchars(
        $selectedDateFormatted
    )?>

</div>



<?php if (empty($rows)): ?>


<div class="wrap no-data">

    Keine Messdaten für
    <?=htmlspecialchars(
        $selectedDateFormatted
    )?>
    vorhanden.

</div>


<?php else: ?>


<!-- Statuskarten -->

<div class="cards">


<?php foreach ($summary as $target => $s): ?>


<div class="card">


<div class="card-header">


<div>


<div class="card-title">

    <?=htmlspecialchars($target)?>

</div>


<div class="address">

    <?=htmlspecialchars(
        $s['address']
    )?>

</div>


</div>



<div
    class="status-dot <?=statusClass(
        $target,
        $s['current'],
        $s['current_loss']
    )?>"
    title="Status"
></div>


</div>



<div class="current">


<?php if ($s['current'] !== null): ?>


<?=number_format(
    $s['current'],
    2,
    ',',
    '.'
)?>


<small>
ms
</small>


<?php else: ?>


nicht erreichbar


<?php endif; ?>


</div>



<div class="stats">


Ø Latenz:

<?=$s['avg'] !== null
    ? number_format(
        $s['avg'],
        2,
        ',',
        '.'
      ) . ' ms'
    : '–'
?>


<br>


Min / Max:

<?=$s['min'] !== null
    ? number_format(
        $s['min'],
        2,
        ',',
        '.'
      )
    : '–'
?>

/

<?=$s['max'] !== null
    ? number_format(
        $s['max'],
        2,
        ',',
        '.'
      ) . ' ms'
    : '–'
?>


<br>


Jitter (mdev):

<?=$s['mdev'] !== null
    ? number_format(
        $s['mdev'],
        2,
        ',',
        '.'
      ) . ' ms'
    : '–'
?>


<br>


Paketverlust Ø:

<strong>

<?=number_format(
    $s['loss'],
    2,
    ',',
    '.'
)?> %

</strong>


<br>


Paketverlust aktuell:

<strong>

<?=number_format(
    $s['current_loss'],
    1,
    ',',
    '.'
)?> %

</strong>


<br>


Messungen:

<?=number_format(
    $s['count'],
    0,
    ',',
    '.'
)?>


<br>


Letzte Messung:

<?=htmlspecialchars(
    $s['last_time']
)?>


</div>


</div>


<?php endforeach; ?>


</div>



<!-- Latenzdiagramm -->

<div class="wrap">


<h2>
Latenz
</h2>


<div class="chart-container">

<canvas id="latencyChart"></canvas>

</div>


</div>



<!-- Paketverlustdiagramm -->

<div class="wrap">


<h2>
Paketverlust
</h2>


<div class="chart-container">

<canvas id="lossChart"></canvas>

</div>


</div>



<!-- Tabelle -->

<div class="wrap table-wrap">


<h2>

    Messungen am
    <?=htmlspecialchars(
        $selectedDateFormatted
    )?>

</h2>


<table>


<thead>


<tr>

<th>Zeit</th>

<th>Ziel</th>

<th>Adresse</th>

<th>Gesendet</th>

<th>Empfangen</th>

<th>Loss %</th>

<th>min</th>

<th>avg</th>

<th>max</th>

<th>Jitter (mdev)</th>

</tr>


</thead>



<tbody>


<?php foreach ($recent as $r): ?>


<?php

if ($r['loss'] == 0) {

    $lossCss = 'loss-good';

} elseif ($r['loss'] < 5) {

    $lossCss = 'loss-warn';

} else {

    $lossCss = 'loss-bad';
}

?>


<tr>


<td>

<?=htmlspecialchars(
    date(
        'H:i:s',
        $r['ts']
    )
)?>

</td>


<td>

<?=htmlspecialchars(
    $r['target']
)?>

</td>


<td>

<?=htmlspecialchars(
    $r['address']
)?>

</td>


<td>

<?=$r['sent']?>

</td>


<td>

<?=$r['recv']?>

</td>


<td class="<?=$lossCss?>">

<?=number_format(
    $r['loss'],
    1,
    ',',
    '.'
)?>

</td>


<td>

<?=$r['min'] !== null
    ? number_format(
        $r['min'],
        2,
        ',',
        '.'
      )
    : '–'
?>

</td>


<td>

<?=$r['avg'] !== null
    ? number_format(
        $r['avg'],
        2,
        ',',
        '.'
      )
    : '–'
?>

</td>


<td>

<?=$r['max'] !== null
    ? number_format(
        $r['max'],
        2,
        ',',
        '.'
      )
    : '–'
?>

</td>


<td>

<?=$r['mdev'] !== null
    ? number_format(
        $r['mdev'],
        2,
        ',',
        '.'
      )
    : '–'
?>

</td>


</tr>


<?php endforeach; ?>


</tbody>


</table>


</div>



<div class="footer">

    <?=number_format(
        count($rows),
        0,
        ',',
        '.'
    )?>

    Datensätze am
    <?=htmlspecialchars(
        $selectedDateFormatted
    )?>

    · Messintervall 3 Minuten

</div>



<script>


const latencyDatasets =
<?=json_encode(
    $latencyDatasets,
    JSON_NUMERIC_CHECK |
    JSON_UNESCAPED_SLASHES
)?>;


const lossDatasets =
<?=json_encode(
    $lossDatasets,
    JSON_NUMERIC_CHECK |
    JSON_UNESCAPED_SLASHES
)?>;



function timeLabel(value) {

    const d =
        new Date(value);

    const pad =
        n =>
            String(n)
            .padStart(
                2,
                '0'
            );


    return (
        pad(d.getHours())
        +
        ':'
        +
        pad(d.getMinutes())
    );
}



/*
 * Latenz
 */

new Chart(
    document.getElementById(
        'latencyChart'
    ),
    {

        type:
            'line',

        data: {

            datasets:
                latencyDatasets
        },

        options: {

            responsive:
                true,

            maintainAspectRatio:
                false,

            animation:
                false,

            interaction: {

                mode:
                    'nearest',

                intersect:
                    false
            },

            scales: {

                x: {

                    type:
                        'linear',

                    ticks: {

                        maxTicksLimit:
                            16,

                        callback:
                            value =>
                                timeLabel(
                                    value
                                )
                    }
                },

                y: {

                    beginAtZero:
                        true,

                    title: {

                        display:
                            true,

                        text:
                            'RTT Durchschnitt (ms)'
                    }
                }
            },

            plugins: {

                tooltip: {

                    callbacks: {

                        title:
                            items =>
                                new Date(
                                    items[0].parsed.x
                                )
                                .toLocaleString(
                                    'de-DE'
                                ),

                        label:
                            item =>
                                item.dataset.label
                                +
                                ': '
                                +
                                (
                                    item.parsed.y === null

                                    ? 'nicht erreichbar'

                                    : item.parsed.y
                                        .toFixed(2)
                                        +
                                        ' ms'
                                )
                    }
                }
            }
        }
    }
);



/*
 * Paketverlust
 */

new Chart(
    document.getElementById(
        'lossChart'
    ),
    {

        type:
            'line',

        data: {

            datasets:
                lossDatasets
        },

        options: {

            responsive:
                true,

            maintainAspectRatio:
                false,

            animation:
                false,

            interaction: {

                mode:
                    'nearest',

                intersect:
                    false
            },

            scales: {

                x: {

                    type:
                        'linear',

                    ticks: {

                        maxTicksLimit:
                            16,

                        callback:
                            value =>
                                timeLabel(
                                    value
                                )
                    }
                },

                y: {

                    beginAtZero:
                        true,

                    suggestedMax:
                        10,

                    title: {

                        display:
                            true,

                        text:
                            'Paketverlust (%)'
                    }
                }
            },

            plugins: {

                tooltip: {

                    callbacks: {

                        title:
                            items =>
                                new Date(
                                    items[0].parsed.x
                                )
                                .toLocaleString(
                                    'de-DE'
                                ),

                        label:
                            item =>
                                item.dataset.label
                                +
                                ': '
                                +
                                item.parsed.y
                                    .toFixed(1)
                                +
                                ' %'
                    }
                }
            }
        }
    }
);


</script>


<?php endif; ?>


</body>

</html>

