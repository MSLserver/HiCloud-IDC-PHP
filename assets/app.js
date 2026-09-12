(function () {
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function apply(mode) {
    var dark = mode === 'dark' || (mode === 'system' && media.matches);
    root.dataset.theme = dark ? 'dark' : 'light';
  }

  var saved = 'system';
  try { saved = localStorage.getItem('theme') || 'system'; } catch (e) {}
  apply(saved);

  media.addEventListener('change', function () {
    var m = 'system';
    try { m = localStorage.getItem('theme') || 'system'; } catch (e) {}
    if (m === 'system') apply('system');
  });

  document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('themeSelect');
    if (sel) {
      sel.value = saved;
      sel.addEventListener('change', function () {
        try { localStorage.setItem('theme', sel.value); } catch (e) {}
        apply(sel.value);
      });
    }

    // 侧边菜单收缩 / 展开
    var tog = document.getElementById('sidebarToggle');
    if (tog) {
      tog.addEventListener('click', function () {
        var collapsed = root.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('sidebar', collapsed ? '1' : '0'); } catch (e) {}
      });
    }

    // 公告弹窗（三天内不再弹出）
    var scrim = document.getElementById('annScrim');
    if (scrim) {
      var hideBtn = document.getElementById('annHide3d');
      var key = 'ann_hide_' + hideBtn.dataset.id;
      var until = 0;
      try { until = parseInt(localStorage.getItem(key) || '0', 10); } catch (e) {}
      if (Date.now() > until) scrim.style.display = 'flex';
      document.getElementById('annClose').addEventListener('click', function () {
        scrim.style.display = 'none';
      });
      hideBtn.addEventListener('click', function () {
        try { localStorage.setItem(key, String(Date.now() + 3 * 24 * 3600 * 1000)); } catch (e) {}
        scrim.style.display = 'none';
      });
    }

    // Material 波纹效果
    document.addEventListener('pointerdown', function (e) {
      var btn = e.target.closest ? e.target.closest('.md-btn') : null;
      if (!btn || btn.disabled) return;
      var rect = btn.getBoundingClientRect();
      var size = Math.max(rect.width, rect.height);
      var r = document.createElement('span');
      r.className = 'ripple';
      r.style.width = r.style.height = size + 'px';
      r.style.left = (e.clientX - rect.left - size / 2) + 'px';
      r.style.top = (e.clientY - rect.top - size / 2) + 'px';
      btn.appendChild(r);
      setTimeout(function () { r.remove(); }, 600);
    });
  });
})();
