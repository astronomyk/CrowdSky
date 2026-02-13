<?php
/**
 * Browse completed stacked frames — sky map (Aitoff) or table view.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$userId = requireLogin();
$db = getDb();

// Optional filter by object
$objectFilter = $_GET['object'] ?? null;

$pageTitle = 'Stacks - CrowdSky';
include __DIR__ . '/templates/header.php';
?>

<h1>Stacked Frames</h1>

<?php
// Get distinct objects for filter
$stmt = $db->prepare(
    'SELECT DISTINCT object_name FROM stacked_frames WHERE user_id = ? AND object_name IS NOT NULL ORDER BY object_name'
);
$stmt->execute([$userId]);
$objects = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<?php if ($objects): ?>
<div style="margin-bottom:1rem">
    <strong>Filter:</strong>
    <a href="stacks.php" class="btn" style="margin-left:0.5rem">All</a>
    <?php foreach ($objects as $obj): ?>
        <a href="stacks.php?object=<?= urlencode($obj) ?>"
           class="btn<?= $objectFilter === $obj ? ' btn-primary' : '' ?>"
           style="margin-left:0.25rem">
            <?= htmlspecialchars($obj) ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="view-toggle">
    <button id="btn-view-map" class="active" onclick="switchView('map')">Sky Map</button>
    <button id="btn-view-table" onclick="switchView('table')">Table</button>
</div>

<!-- Sky Map View -->
<div id="view-map">
    <div class="skymap-container" id="skymap-container">
        <div id="skymap"></div>
    </div>
    <div class="timeline-container" id="timeline-container">
        <div id="timeline"></div>
    </div>
    <div class="selection-summary" id="selection-summary" style="display:none">
        <span class="summary-text" id="summary-text"></span>
        <button class="btn btn-primary" id="btn-download-all" onclick="downloadAll()">Download All</button>
    </div>
</div>

<!-- Table View -->
<div id="view-table" style="display:none">
<?php
$sql = 'SELECT sf.*, sj.upload_session_id
        FROM stacked_frames sf
        JOIN stacking_jobs sj ON sj.id = sf.stacking_job_id
        WHERE sf.user_id = ?';
$params = [$userId];

if ($objectFilter) {
    $sql .= ' AND sf.object_name = ?';
    $params[] = $objectFilter;
}
$sql .= ' ORDER BY sf.date_obs_start DESC LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$stacks = $stmt->fetchAll();
?>

<?php if (empty($stacks)): ?>
    <div class="card">
        <p>No stacked frames yet. Upload some FITS files and wait for the worker to process them.</p>
    </div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Object</th>
                    <th>Chunk</th>
                    <th>Frames</th>
                    <th>Aligned</th>
                    <th>Exp. Time</th>
                    <th>Date</th>
                    <th>Stars</th>
                    <th>Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stacks as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['object_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['chunk_key']) ?></td>
                    <td><?= (int)$s['n_frames_input'] ?></td>
                    <td><?= (int)$s['n_frames_aligned'] ?></td>
                    <td><?= $s['total_exptime'] ? number_format($s['total_exptime'], 1) . 's' : '-' ?></td>
                    <td><?= htmlspecialchars($s['date_obs_start'] ?? '-') ?></td>
                    <td><?= $s['n_stars_detected'] !== null ? (int)$s['n_stars_detected'] : '-' ?></td>
                    <td><?= number_format($s['file_size_bytes'] / 1024 / 1024, 1) ?> MB</td>
                    <td><a href="download.php?id=<?= (int)$s['id'] ?>">Download</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>

<div class="map-tooltip" id="map-tooltip"></div>

<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
(function() {
    // --- View toggle ---
    window.switchView = function(view) {
        document.getElementById('view-map').style.display = view === 'map' ? 'block' : 'none';
        document.getElementById('view-table').style.display = view === 'table' ? 'block' : 'none';
        document.getElementById('btn-view-map').classList.toggle('active', view === 'map');
        document.getElementById('btn-view-table').classList.toggle('active', view === 'table');
    };

    const tooltip = document.getElementById('map-tooltip');
    let allStacks = [];
    let selectedIds = new Set();
    let spatialIds = null;   // null = all selected spatially
    let temporalIds = null;  // null = all selected temporally
    const objectFilter = <?= json_encode($objectFilter) ?>;

    // --- Load data ---
    fetch('api/stacks_data.php')
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('skymap').innerHTML =
                    '<p style="padding:2rem;color:var(--text-muted)">Could not load stack data.</p>';
                return;
            }
            allStacks = data.filter(d => d.ra_deg !== null && d.dec_deg !== null);
            if (objectFilter) {
                allStacks = allStacks.filter(d => d.object_name === objectFilter);
            }
            if (allStacks.length === 0) {
                document.getElementById('skymap').innerHTML =
                    '<p style="padding:2rem;color:var(--text-muted)">No stacks with coordinates yet.</p>';
                document.getElementById('timeline-container').style.display = 'none';
                return;
            }
            drawSkyMap();
            drawTimeline();
            updateSelection();
        });

    // --- Aitoff sky map ---
    function drawSkyMap() {
        const container = document.getElementById('skymap');
        const width = container.clientWidth || 900;
        const height = Math.min(width * 0.5, 500);

        const svg = d3.select('#skymap').append('svg')
            .attr('viewBox', `0 0 ${width} ${height}`)
            .attr('preserveAspectRatio', 'xMidYMid meet');

        const projection = d3.geoAitoff()
            .scale(width / 5.5)
            .translate([width / 2, height / 2])
            .rotate([-180, 0]);  // Center on RA=180 (astronomical convention)

        const path = d3.geoPath(projection);

        // Graticule
        const graticule = d3.geoGraticule().step([30, 15])();
        svg.append('path')
            .datum(graticule)
            .attr('class', 'graticule')
            .attr('d', path);

        // Outline
        svg.append('path')
            .datum(d3.geoGraticule().outline())
            .attr('class', 'outline')
            .attr('d', path);

        // RA tick labels
        for (let ra = 0; ra < 360; ra += 30) {
            const lon = ra > 180 ? ra - 360 : ra;
            const pos = projection([lon, 0]);
            if (pos) {
                svg.append('text')
                    .attr('class', 'tick-label')
                    .attr('x', pos[0])
                    .attr('y', pos[1] + 14)
                    .attr('text-anchor', 'middle')
                    .text(ra + '\u00B0');
            }
        }

        // Dec tick labels
        for (let dec = -60; dec <= 60; dec += 30) {
            if (dec === 0) continue;
            const pos = projection([0, dec]);
            if (pos) {
                svg.append('text')
                    .attr('class', 'tick-label')
                    .attr('x', pos[0] - 8)
                    .attr('y', pos[1] + 3)
                    .attr('text-anchor', 'end')
                    .text((dec > 0 ? '+' : '') + dec + '\u00B0');
            }
        }

        // Group stacks by rounded coordinate
        const groups = d3.group(allStacks, d => {
            const rr = Math.round(d.ra_deg * 10) / 10;
            const dd = Math.round(d.dec_deg * 10) / 10;
            return rr + ',' + dd;
        });

        const pointData = Array.from(groups, ([key, stacks]) => {
            const [ra, dec] = key.split(',').map(Number);
            return {
                ra, dec,
                lon: ra > 180 ? ra - 360 : ra,
                lat: dec,
                count: stacks.length,
                stacks: stacks,
                name: stacks[0].object_name || 'Unknown',
                ids: new Set(stacks.map(s => s.id)),
            };
        });

        const rScale = d3.scaleSqrt()
            .domain([1, d3.max(pointData, d => d.count)])
            .range([4, 14]);

        // Dots
        const dots = svg.selectAll('.stack-dot')
            .data(pointData)
            .join('circle')
            .attr('class', 'stack-dot')
            .attr('cx', d => { const p = projection([d.lon, d.lat]); return p ? p[0] : -999; })
            .attr('cy', d => { const p = projection([d.lon, d.lat]); return p ? p[1] : -999; })
            .attr('r', d => rScale(d.count))
            .on('mouseover', (event, d) => {
                tooltip.innerHTML =
                    '<strong>' + d.name + '</strong><br>' +
                    'RA: ' + d.ra.toFixed(1) + '\u00B0, Dec: ' + (d.dec >= 0 ? '+' : '') + d.dec.toFixed(1) + '\u00B0<br>' +
                    d.count + ' stack' + (d.count > 1 ? 's' : '');
                tooltip.classList.add('visible');
            })
            .on('mousemove', event => {
                tooltip.style.left = (event.pageX + 12) + 'px';
                tooltip.style.top = (event.pageY - 10) + 'px';
            })
            .on('mouseout', () => {
                tooltip.classList.remove('visible');
            });

        // Brush for spatial selection
        const brush = d3.brush()
            .extent([[0, 0], [width, height]])
            .on('end', event => {
                if (!event.selection) {
                    spatialIds = null;
                } else {
                    const [[x0, y0], [x1, y1]] = event.selection;
                    spatialIds = new Set();
                    pointData.forEach(d => {
                        const p = projection([d.lon, d.lat]);
                        if (p && p[0] >= x0 && p[0] <= x1 && p[1] >= y0 && p[1] <= y1) {
                            d.ids.forEach(id => spatialIds.add(id));
                        }
                    });
                }
                updateSelection();
            });

        svg.append('g')
            .attr('class', 'brush')
            .call(brush);

        window._skyDots = dots;
        window._skyPointData = pointData;
    }

    // --- Timeline ---
    function drawTimeline() {
        const container = document.getElementById('timeline');
        const width = container.clientWidth || 900;
        const height = 160;
        const margin = { top: 20, right: 20, bottom: 30, left: 50 };
        const innerW = width - margin.left - margin.right;
        const innerH = height - margin.top - margin.bottom;

        // Parse dates
        allStacks.forEach(d => {
            d._date = d.date_obs_start ? new Date(d.date_obs_start) : null;
        });
        const withDate = allStacks.filter(d => d._date);
        if (withDate.length === 0) {
            document.getElementById('timeline-container').style.display = 'none';
            return;
        }

        const svg = d3.select('#timeline').append('svg')
            .attr('viewBox', `0 0 ${width} ${height}`)
            .attr('preserveAspectRatio', 'xMidYMid meet');

        const g = svg.append('g')
            .attr('transform', `translate(${margin.left},${margin.top})`);

        const xScale = d3.scaleTime()
            .domain(d3.extent(withDate, d => d._date))
            .range([0, innerW])
            .nice();

        const xAxis = d3.axisBottom(xScale).ticks(6);
        g.append('g')
            .attr('transform', `translate(0,${innerH})`)
            .call(xAxis)
            .selectAll('text').style('fill', 'var(--text-muted)');
        g.selectAll('.domain, .tick line').style('stroke', 'var(--border)');

        // Jitter y for visibility
        const dots = g.selectAll('.timeline-dot')
            .data(withDate)
            .join('circle')
            .attr('class', 'timeline-dot')
            .attr('cx', d => xScale(d._date))
            .attr('cy', () => margin.top + Math.random() * (innerH - 2 * margin.top))
            .attr('r', 4);

        // Brush for temporal selection
        const brush = d3.brushX()
            .extent([[0, 0], [innerW, innerH]])
            .on('end', event => {
                if (!event.selection) {
                    temporalIds = null;
                } else {
                    const [x0, x1] = event.selection;
                    const t0 = xScale.invert(x0);
                    const t1 = xScale.invert(x1);
                    temporalIds = new Set();
                    withDate.forEach(d => {
                        if (d._date >= t0 && d._date <= t1) {
                            temporalIds.add(d.id);
                        }
                    });
                }
                updateSelection();
            });

        g.append('g')
            .attr('class', 'brush')
            .call(brush);

        window._timelineDots = dots;
    }

    // --- Selection logic ---
    function updateSelection() {
        // Compute intersection of spatial and temporal selections
        if (spatialIds === null && temporalIds === null) {
            selectedIds = new Set(allStacks.map(s => s.id));
        } else if (spatialIds === null) {
            selectedIds = new Set(temporalIds);
        } else if (temporalIds === null) {
            selectedIds = new Set(spatialIds);
        } else {
            selectedIds = new Set([...spatialIds].filter(id => temporalIds.has(id)));
        }

        // Update sky map dots
        if (window._skyDots) {
            window._skyDots.attr('class', d => {
                const hasSelected = [...d.ids].some(id => selectedIds.has(id));
                return 'stack-dot' + (selectedIds.size < allStacks.length && !hasSelected ? ' dimmed' : '');
            });
        }

        // Update timeline dots
        if (window._timelineDots) {
            window._timelineDots.attr('class', d =>
                'timeline-dot' + (selectedIds.size < allStacks.length && !selectedIds.has(d.id) ? ' dimmed' : '')
            );
        }

        // Update summary
        const summary = document.getElementById('selection-summary');
        const summaryText = document.getElementById('summary-text');
        if (selectedIds.size > 0 && selectedIds.size < allStacks.length) {
            const totalBytes = allStacks
                .filter(s => selectedIds.has(s.id))
                .reduce((sum, s) => sum + s.file_size_bytes, 0);
            const totalMb = (totalBytes / 1024 / 1024).toFixed(1);
            summaryText.innerHTML = '<strong>' + selectedIds.size + '</strong> stack' +
                (selectedIds.size > 1 ? 's' : '') + ' selected (' + totalMb + ' MB total)';
            summary.style.display = 'flex';
        } else if (selectedIds.size === allStacks.length) {
            summary.style.display = 'none';
        } else {
            summary.style.display = 'none';
        }
    }

    // --- Download All ---
    window.downloadAll = function() {
        const ids = [...selectedIds];
        if (ids.length === 0) return;
        // Sequential download via hidden iframes
        let i = 0;
        function next() {
            if (i >= ids.length) return;
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = 'download.php?id=' + ids[i];
            document.body.appendChild(iframe);
            i++;
            setTimeout(next, 500);
        }
        next();
    };
})();
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
