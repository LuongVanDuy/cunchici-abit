(function () {
  'use strict';

  const cfg = window.CunchiciAbitAdmin || {};
  if (!cfg.ajaxUrl || !cfg.nonce) return;

  async function post(action, data = {}) {
    const body = new URLSearchParams({ action, nonce: cfg.nonce });
    Object.keys(data).forEach((key) => {
      const value = data[key];
      body.set(key, value == null ? '' : String(value));
    });
    const response = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body,
      credentials: 'same-origin'
    });
    const json = await response.json();
    if (!json.success) {
      const error = new Error(json.data && json.data.message ? json.data.message : 'Yêu cầu thất bại.');
      error.payload = json.data || {};
      throw error;
    }
    return json.data;
  }

  function $(id) { return document.getElementById(id); }
  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function formatPrice(value) {
    const number = Number(value || 0);
    return new Intl.NumberFormat('vi-VN').format(number) + ' ₫';
  }
  function statusLabel(status) {
    return { pending: 'Chờ đồng bộ', synced: 'Đã đồng bộ', error: 'Lỗi' }[status] || status || '—';
  }
  function setBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.textContent = busyText || 'Đang xử lý...';
      button.disabled = true;
    } else {
      button.textContent = button.dataset.originalText || button.textContent;
      button.disabled = false;
    }
  }

  // Settings diagnostic.
  const testButton = $('ccabit-test-connection');
  if (testButton) {
    testButton.addEventListener('click', async () => {
      const out = $('ccabit-test-result');
      out.hidden = false;
      out.textContent = 'Đang kiểm tra...';
      setBusy(testButton, true, 'Đang kiểm tra...');
      try {
        const data = await post('cunchici_abit_test_connection');
        out.textContent = data.message + '\n\n' + JSON.stringify(data.diagnostics, null, 2);
      } catch (error) {
        out.textContent = 'Lỗi: ' + error.message;
      } finally {
        setBusy(testButton, false);
      }
    });
  }

  const app = $('ccabit-sync-app');
  if (!app) return;

  let currentPage = 1;
  let totalPages = 1;
  let selected = new Set();
  let discoveryLoop = false;
  let runLoop = false;
  let currentRun = null;
  let lastFilters = { search: '', status: 'pending', category: '' };

  function filters() {
    return {
      search: $('ccabit-filter-search').value.trim(),
      status: $('ccabit-filter-status').value,
      category: $('ccabit-filter-category').value
    };
  }

  function updateCounts(counts) {
    if (!counts) return;
    $('ccabit-count-pending').textContent = Number(counts.pending || 0);
    $('ccabit-count-synced').textContent = Number(counts.synced || 0);
    $('ccabit-count-error').textContent = Number(counts.error || 0);
  }

  function updateCategories(categories) {
    const select = $('ccabit-filter-category');
    const current = select.value;
    const options = ['<option value="">Tất cả danh mục Abit</option>'];
    (categories || []).forEach((category) => {
      options.push('<option value="' + escapeHtml(category) + '">' + escapeHtml(category) + '</option>');
    });
    select.innerHTML = options.join('');
    if ([...select.options].some((option) => option.value === current)) select.value = current;
  }

  function renderRows(rows) {
    const tbody = $('ccabit-products-table').querySelector('tbody');
    if (!rows || rows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="ccabit-empty">Không có sản phẩm phù hợp.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map((row) => {
      const checked = selected.has(Number(row.id)) ? ' checked' : '';
      const error = row.last_error ? '<span class="ccabit-error-text">' + escapeHtml(row.last_error) + '</span>' : '—';
      return '<tr>' +
        '<th class="check-column"><input type="checkbox" class="ccabit-row-check" value="' + Number(row.id) + '"' + checked + '></th>' +
        '<td><code>' + escapeHtml(row.sku || '—') + '</code></td>' +
        '<td><strong>' + escapeHtml(row.product_name) + '</strong><div class="ccabit-row-meta">Abit #' + escapeHtml(row.abit_product_id) + '</div></td>' +
        '<td>' + escapeHtml(row.category_label || '—') + '</td>' +
        '<td>' + escapeHtml(formatPrice(row.price)) + '</td>' +
        '<td>' + escapeHtml(row.modified_time || row.created_time || '—') + '</td>' +
        '<td><span class="ccabit-status ccabit-status-' + escapeHtml(row.sync_status) + '">' + escapeHtml(statusLabel(row.sync_status)) + '</span></td>' +
        '<td>' + error + '</td>' +
      '</tr>';
    }).join('');

    tbody.querySelectorAll('.ccabit-row-check').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const id = Number(checkbox.value);
        if (checkbox.checked) selected.add(id); else selected.delete(id);
        updateSelectedButton();
      });
    });
  }

  function updateSelectedButton() {
    const button = $('ccabit-sync-selected');
    button.textContent = selected.size ? 'Đồng bộ ' + selected.size + ' sản phẩm đã chọn' : 'Đồng bộ sản phẩm đã chọn';
    button.disabled = selected.size === 0;
  }

  async function loadCandidates(page = 1) {
    currentPage = page;
    const f = filters();
    lastFilters = f;
    try {
      const data = await post('cunchici_abit_candidates', {
        page_num: currentPage,
        search: f.search,
        status: f.status,
        category: f.category
      });
      totalPages = Number(data.total_pages || 1);
      renderRows(data.rows || []);
      updateCategories(data.categories || []);
      updateCounts(data.counts || {});
      $('ccabit-result-count').textContent = Number(data.total || 0) + ' sản phẩm';
      $('ccabit-page-info').textContent = 'Trang ' + Number(data.page || 1) + ' / ' + totalPages;
      $('ccabit-prev-page').disabled = currentPage <= 1;
      $('ccabit-next-page').disabled = currentPage >= totalPages;
      if (!currentRun && data.open_run) {
        currentRun = data.open_run;
        renderRun(currentRun);
      }
      updateSelectedButton();
    } catch (error) {
      $('ccabit-products-table').querySelector('tbody').innerHTML = '<tr><td colspan="8" class="ccabit-error-text">' + escapeHtml(error.message) + '</td></tr>';
    }
  }

  $('ccabit-apply-filter').addEventListener('click', () => {
    selected.clear();
    $('ccabit-select-all-page').checked = false;
    loadCandidates(1);
  });
  $('ccabit-filter-search').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault(); selected.clear(); loadCandidates(1);
    }
  });
  $('ccabit-prev-page').addEventListener('click', () => currentPage > 1 && loadCandidates(currentPage - 1));
  $('ccabit-next-page').addEventListener('click', () => currentPage < totalPages && loadCandidates(currentPage + 1));
  $('ccabit-select-all-page').addEventListener('change', (event) => {
    app.querySelectorAll('.ccabit-row-check').forEach((checkbox) => {
      checkbox.checked = event.target.checked;
      const id = Number(checkbox.value);
      if (checkbox.checked) selected.add(id); else selected.delete(id);
    });
    updateSelectedButton();
  });

  // Discovery.
  function discoveryStateFromDom() {
    const node = $('ccabit-discovery-progress');
    try { return JSON.parse(node.dataset.state || '{}') || {}; } catch (e) { return {}; }
  }
  let discoveryState = discoveryStateFromDom();

  function renderDiscovery(state) {
    discoveryState = state || {};
    $('ccabit-discovery-progress').dataset.state = JSON.stringify(discoveryState);
    $('ccabit-discovery-status').textContent = discoveryState.status || 'idle';
    const text = $('ccabit-discovery-progress').querySelector('.ccabit-progress-text');
    const bar = $('ccabit-discovery-progress').querySelector('.ccabit-progress-bar');
    if (!discoveryState.status || discoveryState.status === 'idle') {
      text.textContent = 'Chưa có lần quét.';
      bar.style.width = '0%';
      return;
    }
    const parts = [
      'Trang API: ' + Number(discoveryState.page || 0),
      'Đã đọc: ' + Number(discoveryState.fetched || 0),
      'Mới: ' + Number(discoveryState.created || 0),
      'Thay đổi: ' + Number(discoveryState.changed || 0),
      'Không đổi: ' + Number(discoveryState.unchanged || 0)
    ];
    if (discoveryState.last_error) parts.push('Lỗi: ' + discoveryState.last_error);
    text.textContent = parts.join(' · ');
    bar.style.width = discoveryState.status === 'completed' ? '100%' : '35%';
    bar.classList.toggle('is-indeterminate', discoveryState.status === 'running');
  }

  async function discoveryStepLoop() {
    if (discoveryLoop) return;
    discoveryLoop = true;
    renderDiscovery(Object.assign({}, discoveryState, { status: 'running' }));
    try {
      while (discoveryLoop) {
        const data = await post('cunchici_abit_discovery_next');
        renderDiscovery(data.state);
        updateCounts(data.counts || {});
        if (!data.state.has_more || data.state.status === 'completed') {
          discoveryLoop = false;
          await loadCandidates(1);
          break;
        }
      }
    } catch (error) {
      discoveryLoop = false;
      renderDiscovery((error.payload && error.payload.state) || Object.assign({}, discoveryState, { status: 'paused', last_error: error.message }));
      window.alert('Quét đã dừng: ' + error.message);
    }
  }

  async function startDiscovery(initialFull) {
    if (initialFull && !window.confirm('Quét toàn bộ catalog Abit? Thao tác này chỉ tạo danh sách chờ, chưa ghi WooCommerce.')) return;
    try {
      const data = await post('cunchici_abit_discovery_start', {
        initial_full: initialFull ? 1 : 0,
        date_time_start: $('ccabit-date-start').value,
        date_time_end: $('ccabit-date-end').value
      });
      renderDiscovery(data.state);
      discoveryStepLoop();
    } catch (error) {
      window.alert(error.message);
      if (error.payload && error.payload.state) renderDiscovery(error.payload.state);
    }
  }

  $('ccabit-scan-full').addEventListener('click', () => startDiscovery(true));
  $('ccabit-scan-incremental').addEventListener('click', () => startDiscovery(false));
  $('ccabit-discovery-continue').addEventListener('click', () => discoveryStepLoop());
  $('ccabit-discovery-pause').addEventListener('click', async () => {
    discoveryLoop = false;
    try { const data = await post('cunchici_abit_discovery_pause'); renderDiscovery(data.state); } catch (e) { window.alert(e.message); }
  });
  $('ccabit-discovery-cancel').addEventListener('click', async () => {
    if (!window.confirm('Hủy lần quét đang dở? Các sản phẩm đã quét vẫn được giữ trong danh sách chờ.')) return;
    discoveryLoop = false;
    try { const data = await post('cunchici_abit_discovery_cancel'); renderDiscovery(data.state); } catch (e) { window.alert(e.message); }
  });

  // Sync run.
  function renderRun(run) {
    if (!run) return;
    currentRun = run;
    const total = Number(run.total || 0);
    const processed = Number(run.processed || 0);
    const percent = total ? Math.min(100, Math.round((processed / total) * 1000) / 10) : 100;
    $('ccabit-run-percent').textContent = percent + '%';
    $('ccabit-run-bar').style.width = percent + '%';
    $('ccabit-run-processed').textContent = processed;
    $('ccabit-run-total').textContent = total;
    $('ccabit-run-success').textContent = Number(run.succeeded || 0);
    $('ccabit-run-failed').textContent = Number(run.failed || 0);
    $('ccabit-run-current').textContent = run.current_product_name ? 'Đang xử lý: ' + run.current_product_name : 'Trạng thái: ' + (run.status || '—');
    $('ccabit-run-pause').disabled = run.status !== 'running' && run.status !== 'queued';
    $('ccabit-run-resume').disabled = !['paused', 'queued', 'running'].includes(run.status);
    $('ccabit-run-cancel').disabled = ['completed', 'cancelled'].includes(run.status);
  }

  function renderStep(data) {
    renderRun(data);
    const log = $('ccabit-run-log');
    if (data.product) {
      const row = document.createElement('div');
      row.className = data.error ? 'ccabit-log-row is-error' : 'ccabit-log-row is-success';
      const action = data.error ? 'LỖI' : (data.action === 'created' ? 'TẠO MỚI' : 'CẬP NHẬT');
      row.textContent = '[' + action + '] ' + (data.product.sku || 'no-sku') + ' — ' + data.product.name + (data.error ? ' — ' + data.error : '');
      log.prepend(row);
    }
  }

  async function runSteps() {
    if (runLoop || !currentRun) return;
    runLoop = true;
    try {
      while (runLoop) {
        const data = await post('cunchici_abit_run_step', { run_id: currentRun.id || currentRun.run_id });
        currentRun = Object.assign({}, currentRun, data);
        renderStep(data);
        if (['completed', 'cancelled'].includes(data.status)) {
          runLoop = false;
          selected.clear();
          await loadCandidates(currentPage);
          break;
        }
      }
    } catch (error) {
      runLoop = false;
      if (error.payload && error.payload.run) { currentRun = error.payload.run; renderRun(currentRun); }
      window.alert('Đồng bộ đã dừng: ' + error.message);
    }
  }

  async function createRun(useSelected) {
    const f = filters();
    const ids = useSelected ? Array.from(selected) : [];
    if (useSelected && ids.length === 0) return;
    if (!useSelected) {
      const warning = f.status === '' ? 'Bộ lọc hiện gồm cả sản phẩm đã đồng bộ. Bạn chắc chắn muốn tạo phiên cho TẤT CẢ kết quả?' : 'Đồng bộ tất cả sản phẩm đang khớp bộ lọc hiện tại?';
      if (!window.confirm(warning)) return;
    } else if (!window.confirm('Đồng bộ ' + ids.length + ' sản phẩm đã chọn?')) return;

    const categoryMode = $('ccabit-category-mode').value;
    if (categoryMode === 'fixed' && Number($('ccabit-fixed-category').value || 0) === 0) {
      window.alert('Hãy chọn danh mục WooCommerce cố định.'); return;
    }

    try {
      const data = await post('cunchici_abit_create_run', {
        selected_ids: ids.join(','),
        search: f.search,
        status: f.status,
        category: f.category,
        category_mode: categoryMode,
        fixed_category_id: $('ccabit-fixed-category').value,
        new_product_status: $('ccabit-new-status').value
      });
      currentRun = data.run;
      $('ccabit-run-log').innerHTML = '';
      renderRun(currentRun);
      if (Number(currentRun.total || 0) === 0) {
        window.alert('Không có sản phẩm nào trong phiên đồng bộ.'); return;
      }
      runSteps();
    } catch (error) {
      window.alert(error.message);
    }
  }

  $('ccabit-sync-selected').addEventListener('click', () => createRun(true));
  $('ccabit-sync-filtered').addEventListener('click', () => createRun(false));
  $('ccabit-category-mode').addEventListener('change', (event) => {
    $('ccabit-fixed-category').disabled = event.target.value !== 'fixed';
  });
  $('ccabit-category-mode').dispatchEvent(new Event('change'));

  $('ccabit-run-pause').addEventListener('click', async () => {
    if (!currentRun) return;
    runLoop = false;
    try {
      const data = await post('cunchici_abit_run_status', { run_id: currentRun.id || currentRun.run_id, run_action: 'pause' });
      currentRun = data.run; renderRun(currentRun);
    } catch (e) { window.alert(e.message); }
  });
  $('ccabit-run-resume').addEventListener('click', async () => {
    if (!currentRun) return;
    try {
      const data = await post('cunchici_abit_run_status', { run_id: currentRun.id || currentRun.run_id, run_action: 'resume' });
      currentRun = data.run; renderRun(currentRun); runSteps();
    } catch (e) { window.alert(e.message); }
  });
  $('ccabit-run-cancel').addEventListener('click', async () => {
    if (!currentRun || !window.confirm('Hủy phiên đồng bộ? Các sản phẩm đã xử lý sẽ được giữ nguyên.')) return;
    runLoop = false;
    try {
      const data = await post('cunchici_abit_run_status', { run_id: currentRun.id || currentRun.run_id, run_action: 'cancel' });
      currentRun = data.run; renderRun(currentRun); await loadCandidates(currentPage);
    } catch (e) { window.alert(e.message); }
  });

  renderDiscovery(discoveryState);
  const openRunNode = $('ccabit-run-panel');
  if (openRunNode && openRunNode.dataset.openRun) {
    try {
      const parsed = JSON.parse(openRunNode.dataset.openRun);
      if (parsed) { currentRun = parsed; renderRun(parsed); }
    } catch (e) { /* ignore */ }
  }
  updateSelectedButton();
  loadCandidates(1);
})();
