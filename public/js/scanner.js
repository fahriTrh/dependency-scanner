// ==========================
// Konfigurasi halaman (di-set halaman sebelum skrip ini dimuat)
//   payloadMode: 'form'    → baca input dari form scan (halaman utama)
//   payloadMode: 'project' → payload kosong, server pakai sumber proyek tersimpan
// ==========================
const CONFIG = Object.assign(
    { scanUrl: '/api/scan', exportUrl: '/api/scan/export', payloadMode: 'form' },
    window.SCANNER_CONFIG || {}
);

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ==========================
// IKON SVG (pengganti emoji) — mengikut warna teks lewat currentColor
// ==========================
const ICON = {
    warning: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline-block shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.8 19.5a1.2 1.2 0 0 0 1 1.8h16.4a1.2 1.2 0 0 0 1-1.8L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v4.5M12 17.6h.01"/></svg>',
    file: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline-block align-[-0.15em]" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
    check: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline-block shrink-0" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-5"/></svg>',
    swap: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 4 4 7.5 7.5 11M4 7.5h13M16.5 13 20 16.5 16.5 20M20 16.5h-13"/></svg>',
    close: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18"/></svg>',
    arrowUpRight: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline-block align-[-0.05em]" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8.5 7H17v8.5"/></svg>'
};

function requestHeaders(extra) {
    return Object.assign({}, extra || {}, CSRF_TOKEN ? { 'X-CSRF-TOKEN': CSRF_TOKEN } : {});
}

// ==========================
// TABS (hanya di halaman utama)
// ==========================
const tabs = document.querySelectorAll('#tabs .tab');
tabs.forEach(btn => btn.addEventListener('click', () => {
    tabs.forEach(b => {
        b.classList.remove('bg-indigo-600', 'text-white');
        b.classList.add('bg-slate-200', 'text-slate-700', 'dark:bg-slate-800', 'dark:text-slate-300');
    });
    btn.classList.add('bg-indigo-600', 'text-white');
    btn.classList.remove('bg-slate-200', 'text-slate-700', 'dark:bg-slate-800', 'dark:text-slate-300');
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== btn.dataset.tab));
}));

const sevColors = {critical:'bg-red-600', high:'bg-orange-500', moderate:'bg-yellow-500', medium:'bg-yellow-500', low:'bg-green-500', unknown:'bg-slate-400'};
const sevText = {critical:'text-red-700 dark:text-red-400', high:'text-orange-600 dark:text-orange-400', moderate:'text-yellow-600 dark:text-yellow-400', medium:'text-yellow-600 dark:text-yellow-400', low:'text-green-600 dark:text-green-400', unknown:'text-slate-500 dark:text-slate-400'};

const scanForm = document.getElementById('scan-form');
if (scanForm) {
    scanForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('scan-btn');
        const loading = document.getElementById('loading');
        const errorBox = document.getElementById('error-box');
        const resultBox = document.getElementById('result');

        let fd;
        try { fd = buildPayload(form); } catch (msg) { showError(msg); return; }

        btn.disabled = true; loading.classList.remove('hidden');
        errorBox.classList.add('hidden'); resultBox.classList.add('hidden');

        try {
            const res = await fetch(CONFIG.scanUrl, {method: 'POST', body: fd, headers: requestHeaders({'Accept': 'application/json'})});
            const data = await res.json();
            if (!res.ok) { showError(data.message || 'Terjadi kesalahan.'); return; }
            renderResult(data);
            const exportCsv = document.getElementById('export-csv');
            const exportPdf = document.getElementById('export-pdf');
            const exportSarif = document.getElementById('export-sarif');
            if (exportCsv) exportCsv.classList.remove('hidden');
            if (exportPdf) exportPdf.classList.remove('hidden');
            if (exportSarif) exportSarif.classList.remove('hidden');
        } catch (err) {
            showError('Gagal terhubung ke server: ' + err.message);
        } finally {
            btn.disabled = false; loading.classList.add('hidden');
        }
    });
}

function buildPayload(form) {
    // Mode proyek: sumber diambil server dari proyek tersimpan
    if (CONFIG.payloadMode === 'project') {
        return new FormData();
    }

    const activeTab = document.querySelector('#tabs .tab.bg-indigo-600').dataset.tab;
    const fd = new FormData();

    if (activeTab === 'paste') {
        const c = form.composer_json.value.trim(), p = form.package_json.value.trim();
        const cl = form.composer_lock.value.trim(), pl = form.package_lock.value.trim();
        if (!c && !p && !cl && !pl) { throw 'Isi minimal satu textarea.'; }
        if (c) fd.append('composer_json', c);
        if (p) fd.append('package_json', p);
        if (cl) fd.append('composer_lock', cl);
        if (pl) fd.append('package_lock', pl);
    } else if (activeTab === 'upload') {
        const pick = key => form[key].files[0] || null;
        const c = pick('files[composer]'), p = pick('files[package]');
        const cl = pick('files[composer_lock]'), pl = pick('files[package_lock]');
        if (!c && !p && !cl && !pl) { throw 'Pilih minimal satu file.'; }
        if (c) fd.append('files[composer]', c);
        if (p) fd.append('files[package]', p);
        if (cl) fd.append('files[composer_lock]', cl);
        if (pl) fd.append('files[package_lock]', pl);
    } else {
        const repo = form.repo.value.trim();
        if (!repo) { throw 'Masukkan nama repo atau URL GitHub.'; }
        fd.append('repo', repo);
        if (form.branch.value.trim()) fd.append('branch', form.branch.value.trim());
    }

    return fd;
}

async function exportReport(format) {
    const form = document.getElementById('scan-form');
    let fd;
    try { fd = buildPayload(form); } catch (msg) { showError(msg); return; }

    const btn = document.getElementById(format === 'pdf' ? 'export-pdf' : 'export-csv');
    btn.disabled = true;

    try {
        const res = await fetch(CONFIG.exportUrl + '?format=' + format, {method: 'POST', body: fd, headers: requestHeaders()});
        if (!res.ok) {
            let msg = 'Gagal membuat laporan (' + res.status + ').';
            try { const j = await res.json(); if (j.message) msg = j.message; } catch (_) {}
            showError(msg);
            return;
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = (res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/)?.[1] || ('laporan-scan.' + format);
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        showError('Gagal mengunduh laporan: ' + err.message);
    } finally {
        btn.disabled = false;
    }
}

const exportCsvBtn = document.getElementById('export-csv');
const exportPdfBtn = document.getElementById('export-pdf');
const exportSarifBtn = document.getElementById('export-sarif');
if (exportCsvBtn) exportCsvBtn.addEventListener('click', () => exportReport('csv'));
if (exportPdfBtn) exportPdfBtn.addEventListener('click', () => exportReport('pdf'));
if (exportSarifBtn) exportSarifBtn.addEventListener('click', () => exportReport('sarif'));

function showError(msg) {
    const box = document.getElementById('error-box');
    box.innerHTML = ICON.warning + ' ' + esc(msg);
    box.classList.remove('hidden');
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Constraint yang disarankan: pin exact jika dalam range, '^versi' jika di luar range
function suggestedConstraint(p) {
    if (p.upgrade.within_range) return p.upgrade.version;
    return /^\d+\.\d+\.\d+$/.test(p.upgrade.version) ? ('^' + p.upgrade.version) : p.upgrade.version;
}

// Versi terpasang di lock file (jika berbeda dari constraint manifest)
function installedLabel(p) {
    if (!p.installed_version || p.installed_version === p.constraint) return '';
    return ' <span class="text-[11px] text-sky-600 dark:text-sky-400 font-normal" title="Versi terpasang di lock file">→ ' + esc(p.installed_version) + '</span>';
}

function diffPanelHtml(p) {
    const target = suggestedConstraint(p);
    const cmd = p.ecosystem === 'composer'
        ? (p.upgrade.within_range
            ? `composer update ${p.name} --with-dependencies`
            : `composer require ${p.name}:"${target}"`)
        : (p.upgrade.within_range
            ? `npm update ${p.name}`
            : `npm install ${p.name}@${p.upgrade.version}`);

    return `
        <div class="text-rose-400">- "${esc(p.name)}": "${esc(p.constraint)}"</div>
        <div class="text-emerald-400">+ "${esc(p.name)}": "${esc(target)}"${p.upgrade.within_range ? ' <span class="text-slate-400">// opsional (pin) — atau cukup perintah di bawah</span>' : ''}</div>
        <div class="text-sky-300 mt-1">$ ${esc(cmd)}</div>`;
}

function renderResult(data) {
    const box = document.getElementById('result');
    const s = data.summary;
    const riskStyle = {aman:'bg-green-100 text-green-800 dark:bg-green-900/60 dark:text-green-300', tinggi:'bg-orange-100 text-orange-800 dark:bg-orange-900/60 dark:text-orange-300', kritis:'bg-red-100 text-red-800 dark:bg-red-900/60 dark:text-red-300'}[s.risk] || 'bg-slate-100 dark:bg-slate-800 dark:text-slate-300';

    let html = `
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100">Hasil Scan</h2>
            <span class="px-3 py-1 rounded-full text-sm font-semibold ${riskStyle}">Risiko: ${esc(s.risk.toUpperCase())}</span>
        </div>
        <div class="grid grid-cols-3 gap-4 mt-4 text-center">
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-3"><div class="text-2xl font-bold text-slate-800 dark:text-slate-100">${s.total_packages}</div><div class="text-xs text-slate-500 dark:text-slate-400">Total Paket</div></div>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-3"><div class="text-2xl font-bold text-red-600 dark:text-red-400">${s.vulnerable_packages}</div><div class="text-xs text-slate-500 dark:text-slate-400">Rentan</div></div>
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-3"><div class="text-2xl font-bold text-orange-600 dark:text-orange-400">${s.total_advisories}</div><div class="text-xs text-slate-500 dark:text-slate-400">Advisory</div></div>
        </div>
        ${(data.warnings && data.warnings.length) ? `<div class="mt-4 bg-yellow-50 border border-yellow-200 dark:bg-yellow-950 dark:border-yellow-800 rounded-lg p-3 text-xs text-yellow-800 dark:text-yellow-300"><strong>Catatan:</strong><ul class="list-disc ml-4 mt-1">${data.warnings.map(w => `<li>${esc(w)}</li>`).join('')}</ul></div>` : ''}
    </div>`;

    for (const file of data.files) {
        const vuln = file.packages.filter(p => p.vulnerable);
        const clean = file.packages.filter(p => !p.vulnerable && p.checked);
        const skipped = file.packages.filter(p => !p.checked);

        html += `<div class="bg-white dark:bg-slate-900 rounded-xl shadow p-6">
            <h3 class="font-bold text-slate-700 dark:text-slate-200 mb-3">${ICON.file} ${esc(file.file)} <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(${file.packages.length} paket)</span></h3>`;

        if (vuln.length) {
            html += `<div class="space-y-3">` + vuln.map(p => `
                <div class="border border-red-200 dark:border-red-900 rounded-lg p-4 bg-red-50/50 dark:bg-red-950/40">
                    <div class="flex items-center justify-between flex-wrap gap-1">
                        <div class="font-mono text-sm font-bold text-slate-800 dark:text-slate-100">${esc(p.name)} <span class="text-slate-400 dark:text-slate-500 font-normal">${esc(p.constraint)}</span>${installedLabel(p)}${p.dev ? ' <span class="text-xs bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300 px-1.5 rounded">dev</span>' : ''}</div>
                        <div class="flex items-center gap-2">
                            ${p.upgrade && p.upgrade.version ? `<button type="button" data-pkg="${esc(p.name)}" class="diff-toggle text-xs bg-white border border-indigo-300 text-indigo-700 hover:bg-indigo-50 dark:bg-slate-800 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-slate-700 px-2 py-1 rounded-md transition inline-flex items-center gap-1">${ICON.swap} Diff versi aman</button>` : ''}
                            <span class="text-xs text-red-600 dark:text-red-400 font-semibold">${p.advisories.length} advisory</span>
                        </div>
                    </div>
                    ${p.upgrade && p.upgrade.version ? `
                    <div class="mt-1.5 text-xs flex items-center flex-wrap gap-1">
                        <span class="text-slate-500 dark:text-slate-400">Saran upgrade:</span>
                        <span class="font-mono font-bold ${p.upgrade.within_range ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}">${esc(p.upgrade.version)}</span>
                        ${p.upgrade.within_range
                            ? '<span class="text-emerald-600 dark:text-emerald-400">✓ cukup update — constraint tetap</span>'
                            : '<span class="text-amber-600 dark:text-amber-400 inline-flex items-center gap-1">' + ICON.warning + esc(p.upgrade.note || 'perlu ubah constraint') + '</span>'}
                    </div>` : (p.upgrade && p.upgrade.note ? `<div class="mt-1 text-xs text-slate-400 dark:text-slate-500">${esc(p.upgrade.note)}</div>` : '')}
                    ${p.upgrade && p.upgrade.version ? `<div class="diff-panel hidden mt-2 rounded-lg bg-slate-900 dark:bg-slate-800 text-slate-100 p-3 font-mono text-xs leading-relaxed" data-panel-for="${esc(p.name)}">${diffPanelHtml(p)}</div>` : ''}
                    <ul class="mt-2 space-y-2">
                        ${p.advisories.map(a => `
                        <li class="text-sm border-l-2 border-red-300 dark:border-red-800 pl-3">
                            <span class="inline-block ${sevColors[a.severity] || sevColors.unknown} text-white text-[10px] font-bold uppercase px-1.5 py-0.5 rounded mr-1">${esc(a.severity)}</span>
                            <strong>${esc(a.title)}</strong>
                            <span class="text-xs ${sevText[a.severity] || sevText.unknown}">— versi terdampak: ${esc(a.affected_versions ?? '?')}${a.cve ? ' · ' + esc(a.cve) : ''}</span>
                            ${(a.sources || []).map(u => `<a href="${esc(u)}" target="_blank" class="ml-2 text-xs text-indigo-600 dark:text-indigo-400 underline inline-flex items-center gap-0.5">detail ${ICON.arrowUpRight}</a>`).join('')}
                        </li>`).join('')}
                    </ul>
                </div>`).join('') + `</div>`;
        } else {
            html += `<p class="text-sm text-green-700 bg-green-50 border border-green-200 dark:text-green-300 dark:bg-green-950 dark:border-green-900 rounded-lg p-3 flex items-center gap-2">${ICON.check}<span>Tidak ada advisory yang cocok dengan range dependency.</span></p>`;
        }

        if (skipped.length) {
            html += `<details class="mt-3"><summary class="text-xs text-slate-400 dark:text-slate-500 cursor-pointer">${skipped.length} paket dilewati</summary><ul class="text-xs text-slate-500 dark:text-slate-400 list-disc ml-5 mt-1">${skipped.map(p => `<li><span class="font-mono">${esc(p.name)}</span>: ${esc(p.skip_reason ?? '')}</li>`).join('')}</ul></details>`;
        }

        html += `</div>`;
    }

    box.innerHTML = html;

    // Toggle panel diff (delegasi: panel sudah terisi saat render)
    box.querySelectorAll('.diff-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const panel = box.querySelector(`.diff-panel[data-panel-for="${CSS.escape(btn.dataset.pkg)}"]`);
            if (!panel) return;
            panel.classList.toggle('hidden');
            btn.innerHTML = panel.classList.contains('hidden') ? ICON.swap + ' Diff versi aman' : ICON.close + ' Tutup diff';
        });
    });

    box.classList.remove('hidden');
    box.scrollIntoView({behavior: 'smooth'});
}
