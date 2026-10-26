const fileInput = document.getElementById('file-input');
const dropZone = document.getElementById('drop-zone');
const uploadList = document.getElementById('upload-list');
const uploadButton = document.getElementById('upload-button');
const clearButton = document.getElementById('clear-button');
const selectionCount = document.getElementById('selection-count');
const uploadNote = document.getElementById('upload-note');
const fileList = document.getElementById('file-list');
const breadcrumbs = document.getElementById('breadcrumbs');
const fileError = document.getElementById('file-error');

let selectedFiles = [];
// Keep each row's progress/status when the queue is re-rendered after adding files.
const rowStates = new Map();
let currentPath = '';
let uploading = false;

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}
function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB','MB','GB','TB']; let size = bytes / 1024, i = 0;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return `${size.toFixed(1)} ${units[i]}`;
}
function fileKey(file) { return `${file.name}\u0000${file.size}\u0000${file.lastModified}`; }

// Add new selections instead of replacing the existing queue. Exact duplicate selections are ignored.
function addFiles(files) {
    const known = new Set(selectedFiles.map(fileKey));
    let added = 0;
    for (const file of Array.from(files || [])) {
        const key = fileKey(file);
        if (!known.has(key)) {
            selectedFiles.push(file);
            rowStates.set(key, { percent: 0, message: 'Queued', kind: '' });
            known.add(key);
            added++;
        }
    }
    uploadNote.textContent = added ? `Added ${added} file(s) to the queue.` : 'No new files added (they may already be queued).';
    renderUploadList();
}
function renderUploadList() {
    selectionCount.textContent = selectedFiles.length ? `${selectedFiles.length} queued` : 'No files selected';
    uploadButton.disabled = uploading || selectedFiles.length === 0;
    clearButton.disabled = uploading || selectedFiles.length === 0;
    fileInput.disabled = uploading;
    uploadList.replaceChildren();
    selectedFiles.forEach((file, i) => {
        const item = document.createElement('div');
        item.className = 'upload-item';
        const state = rowStates.get(fileKey(file)) || { percent: 0, message: 'Queued', kind: '' };
        item.innerHTML = `<div class="upload-item-top"><div><div class="upload-name">${escapeHtml(file.name)}</div><div class="upload-meta">${formatSize(file.size)}</div></div><span class="upload-percent" id="percent-${i}">${Math.round(state.percent)}%</span></div><div class="progress-track"><div class="progress-fill" id="progress-${i}" style="width:${Math.max(0, Math.min(100, state.percent))}%"></div></div><div class="upload-status ${escapeHtml(state.kind)}" id="status-${i}">${escapeHtml(state.message)}</div>`;
        uploadList.appendChild(item);
    });
}
function updateRow(i, percent, message, kind = '') {
    const file = selectedFiles[i];
    if (file) rowStates.set(fileKey(file), { percent, message, kind });
    const bar = document.getElementById(`progress-${i}`);
    const label = document.getElementById(`percent-${i}`);
    const status = document.getElementById(`status-${i}`);
    if (bar) bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    if (label) label.textContent = `${Math.round(percent)}%`;
    if (status) { status.textContent = message; status.className = `upload-status ${kind}`; }
}
function uploadOne(file, i) {
    return new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        const form = new FormData();
        form.append('files[]', file, file.name);
        xhr.open('POST', '?api=upload');
        xhr.upload.addEventListener('progress', e => {
            if (!e.lengthComputable) return;
            const percent = e.total > 0 ? (e.loaded / e.total * 100) : 0;
            if (e.loaded >= e.total) {
                // Browser has sent all request bytes, but PHP may still be processing
                // the temporary upload and moving it into upload/.
                updateRow(i, 100, 'Data sent. Waiting for the server to finish saving…', 'processing');
            } else {
                updateRow(i, percent, `Uploading… ${formatSize(e.loaded)} of ${formatSize(e.total)}`);
            }
        });
        xhr.upload.addEventListener('load', () => {
            // Upload transfer completed; wait for the PHP response before claiming success.
            updateRow(i, 100, 'Transfer complete. Saving file on server…', 'processing');
        });
        xhr.addEventListener('load', () => {
            let data = null; try { data = JSON.parse(xhr.responseText); } catch (_) {}
            const result = data?.results?.[0];
            if (xhr.status >= 200 && xhr.status < 300 && result?.success) {
                updateRow(i, 100, result.message || 'Upload complete', 'success');
                resolve(true);
            } else {
                updateRow(i, 0, result?.message || data?.error || `Upload failed (HTTP ${xhr.status}).`, 'failure');
                resolve(false);
            }
        });
        xhr.addEventListener('error', () => { updateRow(i, 0, 'Network error. Check server and upload limits.', 'failure'); resolve(false); });
        updateRow(i, 0, 'Starting upload…');
        xhr.send(form);
    });
}
uploadButton.addEventListener('click', async () => {
    if (uploading || !selectedFiles.length) return;
    // Only send files that are queued or previously failed. Successful rows are terminal
    // and are deliberately skipped on later clicks of the Upload button.
    const batch = selectedFiles
        .map((file, index) => ({ file, index, state: rowStates.get(fileKey(file)) }))
        .filter(entry => entry.state?.kind !== 'success');

    if (!batch.length) {
        uploadNote.textContent = 'All queued files are already complete. Add files or clear the queue.';
        return;
    }

    uploading = true;
    renderUploadList();
    uploadNote.textContent = `Uploading ${batch.length} pending or failed file(s)…`;
    const results = await Promise.all(batch.map(({ file, index }) => uploadOne(file, index)));
    const successCount = results.filter(Boolean).length;
    const failedCount = results.length - successCount;
    uploadNote.textContent = failedCount
        ? `Finished: ${successCount} succeeded; ${failedCount} failed. Click Upload to retry failed files.`
        : `Finished: ${successCount} file(s) completed. Previously successful files were skipped.`;
    uploading = false;
    renderUploadList();
    renderControlsOnly();
    await loadDirectory(currentPath);
});
function renderControlsOnly() {
    uploadButton.disabled = uploading || selectedFiles.length === 0;
    clearButton.disabled = uploading || selectedFiles.length === 0;
    fileInput.disabled = uploading;
}
clearButton.addEventListener('click', () => {
    if (uploading) return;
    selectedFiles = [];
    rowStates.clear();
    fileInput.value = '';
    renderUploadList();
    uploadNote.textContent = '';
});
fileInput.addEventListener('change', () => {
    addFiles(fileInput.files);
    // Reset picker value so selecting the same file again can trigger change.
    fileInput.value = '';
});
['dragenter','dragover'].forEach(type => dropZone.addEventListener(type, e => { e.preventDefault(); dropZone.classList.add('drag-over'); }));
['dragleave','drop'].forEach(type => dropZone.addEventListener(type, e => { e.preventDefault(); dropZone.classList.remove('drag-over'); }));
dropZone.addEventListener('drop', e => addFiles(e.dataTransfer.files));
document.getElementById('refresh-button').addEventListener('click', () => loadDirectory(currentPath));

function renderBreadcrumbs(path) {
    breadcrumbs.replaceChildren();
    const parts = path ? path.split('/') : [];
    const crumbs = [{label:'Root',path:''}]; let acc = '';
    parts.forEach(part => { acc = acc ? `${acc}/${part}` : part; crumbs.push({label:part,path:acc}); });
    crumbs.forEach((crumb,i) => {
        if (i) { const sep = document.createElement('span'); sep.className = 'crumb-separator'; sep.textContent = '/'; breadcrumbs.appendChild(sep); }
        const btn = document.createElement('button'); btn.className = `breadcrumb ${i === crumbs.length - 1 ? 'current' : ''}`;
        btn.textContent = crumb.label; btn.disabled = i === crumbs.length - 1; btn.addEventListener('click', () => loadDirectory(crumb.path)); breadcrumbs.appendChild(btn);
    });
}
function renderFiles(items) {
    fileList.replaceChildren();
    if (!items.length) { fileList.innerHTML = '<tr><td colspan="4" class="empty">This folder is empty.</td></tr>'; return; }
    items.forEach(item => {
        const row = document.createElement('tr'), nameCell = document.createElement('td'), nameButton = document.createElement('button');
        nameButton.className = 'file-name';
        const icon = document.createElement('span'); icon.className = `file-icon ${item.type === 'directory' ? 'folder' : ''}`; icon.textContent = item.type === 'directory' ? '📁' : '📄'; icon.setAttribute('aria-hidden','true');
        const name = document.createElement('span'); name.textContent = item.name; nameButton.append(icon,name);
        if (item.type === 'directory') nameButton.addEventListener('click', () => loadDirectory(item.path));
        else nameButton.addEventListener('click', () => { window.location.href = `?download=${encodeURIComponent(item.path)}`; });
        nameCell.appendChild(nameButton);
        const size = document.createElement('td'); size.textContent = item.sizeText;
        const modified = document.createElement('td'); modified.textContent = item.modified;
        const action = document.createElement('td');
        if (item.type === 'file') { const a = document.createElement('a'); a.className = 'download-link'; a.href = `?download=${encodeURIComponent(item.path)}`; a.textContent = 'Download'; action.appendChild(a); } else action.textContent = '—';
        row.append(nameCell,size,modified,action); fileList.appendChild(row);
    });
}
async function loadDirectory(path) {
    currentPath = path; fileError.hidden = true; fileList.innerHTML = '<tr><td colspan="4" class="empty">Loading files…</td></tr>';
    try {
        const response = await fetch(`?api=list&path=${encodeURIComponent(path)}`,{cache:'no-store'});
        const data = await response.json();
        if (!response.ok || data.error) throw new Error(data.error || 'Could not load directory.');
        currentPath = data.path; renderBreadcrumbs(data.path); renderFiles(data.items);
    } catch (error) { fileList.innerHTML = '<tr><td colspan="4" class="empty">Could not load files.</td></tr>'; fileError.textContent = error.message; fileError.hidden = false; }
}
renderUploadList();
loadDirectory('');
