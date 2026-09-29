/* ==========================================================
   Practice Onboarding Tracker - inline editing.

   Every control with data-field / data-task / data-practice saves
   itself to index.php?p=api/task-save. Nothing here is required for
   the app to work: without JavaScript the pages still render and the
   admin forms still post normally.
   ========================================================== */

(function () {
  'use strict';

  var POT = window.POT || { csrf: '', isAdmin: false };

  // ---------------------------------------------------------------
  // Saving one field
  // ---------------------------------------------------------------

  function statusClass(value) {
    return 'st-' + String(value).replace(/_/g, '-');
  }

  function markSaving(el, on) {
    el.classList.toggle('saving', !!on);
    el.classList.remove('save-error');
  }

  function markSaved(el) {
    var row = el.closest('tr') || el;
    row.classList.remove('saved-flash');
    // Restart the CSS animation.
    void row.offsetWidth;
    row.classList.add('saved-flash');
    window.setTimeout(function () { row.classList.remove('saved-flash'); }, 1200);
  }

  function markError(el, message) {
    el.classList.add('save-error');
    showToast(message || 'Could not save that change.');
  }

  var toastEl = null;
  function showToast(message) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'flash flash-error';
      toastEl.setAttribute('role', 'alert');
      toastEl.style.position = 'fixed';
      toastEl.style.left = '50%';
      toastEl.style.bottom = '1.25rem';
      toastEl.style.transform = 'translateX(-50%)';
      toastEl.style.zIndex = '60';
      toastEl.style.maxWidth = '32rem';
      toastEl.style.boxShadow = '0 8px 24px rgba(22,32,43,.16)';
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    toastEl.hidden = false;
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(function () {
      if (toastEl) { toastEl.hidden = true; }
    }, 6000);
  }

  function saveField(el) {
    var field = el.getAttribute('data-field');
    var taskId = el.getAttribute('data-task');
    var practiceId = el.getAttribute('data-practice');
    if (!field || !taskId || !practiceId) { return; }

    var body = new URLSearchParams();
    body.set('csrf', POT.csrf);
    body.set('practice_id', practiceId);
    body.set('task_id', taskId);
    body.set('field', field);
    body.set('value', el.value == null ? '' : el.value);

    markSaving(el, true);

    fetch('index.php?p=api/task-save', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRF-Token': POT.csrf,
        'X-Requested-With': 'fetch'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('The server returned an unexpected response.');
        });
      })
      .then(function (data) {
        markSaving(el, false);
        if (!data || !data.ok) {
          markError(el, (data && data.error) || 'Could not save that change.');
          return;
        }
        markSaved(el);
        if (field === 'status') {
          applyStatusStyle(el, data.task.status);
          refreshOverdue(el, data.task);
        }
        if (field === 'due_date') {
          refreshOverdue(el, data.task);
        }
        updateRollups(data);
      })
      .catch(function (err) {
        markSaving(el, false);
        markError(el, err && err.message ? err.message : 'Could not reach the server.');
      });
  }

  function applyStatusStyle(select, status) {
    var classes = ['st-not-started', 'st-in-progress', 'st-waiting',
                   'st-blocked', 'st-completed', 'st-not-applicable'];
    classes.forEach(function (c) { select.classList.remove(c); });
    select.classList.add(statusClass(status));

    var row = select.closest('tr');
    if (!row) { return; }
    row.classList.remove('task-done', 'task-na', 'task-blocked');
    if (status === 'completed') { row.classList.add('task-done'); }
    if (status === 'not_applicable') { row.classList.add('task-na'); }
    if (status === 'blocked') { row.classList.add('task-blocked'); }
  }

  function refreshOverdue(el, task) {
    var row = el.closest('tr');
    if (!row) { return; }
    row.classList.toggle('task-overdue', !!(task && task.is_overdue));
  }

  /** Update the product and overall progress bars after a save. */
  function updateRollups(data) {
    if (!data.rollup) { return; }

    var overall = document.querySelector('[data-rollup="overall"]');
    if (overall) { setBar(overall, data.rollup); }

    (data.by_product || []).forEach(function (bp) {
      document.querySelectorAll('[data-rollup-product="' + bp.product_id + '"]').forEach(function (node) {
        setBar(node, bp.rollup);
      });
    });
  }

  function setBar(container, rollup) {
    var fill = container.querySelector('.bar span');
    var num = container.querySelector('.bar-num');
    var bar = container.querySelector('.bar');
    if (fill) { fill.style.width = rollup.progress + '%'; }
    if (num) { num.textContent = rollup.progress + '%'; }
    if (bar) { bar.classList.toggle('bar-done', rollup.progress >= 100); }
    var count = container.querySelector('[data-count]');
    if (count) { count.textContent = rollup.completed + ' of ' + rollup.countable + ' done'; }
  }

  // ---------------------------------------------------------------
  // Wiring
  // ---------------------------------------------------------------

  var debounceTimers = new WeakMap();

  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches('[data-field]')) { return; }
    if (el.tagName === 'TEXTAREA') { return; } // handled on blur
    saveField(el);
  });

  // Notes save when you leave the box, or 1.2s after you stop typing.
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches('textarea[data-field]')) { return; }
    window.clearTimeout(debounceTimers.get(el));
    debounceTimers.set(el, window.setTimeout(function () { saveField(el); }, 1200));
    autoGrow(el);
  });

  document.addEventListener('blur', function (ev) {
    var el = ev.target;
    if (!el.matches || !el.matches('textarea[data-field]')) { return; }
    window.clearTimeout(debounceTimers.get(el));
    saveField(el);
  }, true);

  function autoGrow(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
  }

  document.querySelectorAll('textarea.notes-input').forEach(autoGrow);

  // ---------------------------------------------------------------
  // Bulk selection
  // ---------------------------------------------------------------

  var bulkbar = document.getElementById('bulkbar');
  var bulkN = document.getElementById('bulk-n');

  function refreshBulk() {
    if (!bulkbar) { return; }
    var checked = document.querySelectorAll('.bulk-check:checked').length;
    if (bulkN) { bulkN.textContent = String(checked); }
    bulkbar.hidden = checked === 0;
  }

  document.addEventListener('change', function (ev) {
    if (ev.target.classList && ev.target.classList.contains('bulk-check')) {
      refreshBulk();
    }
  });

  // Shift-click selects a range, the way a file list does.
  var lastChecked = null;
  document.addEventListener('click', function (ev) {
    var box = ev.target;
    if (!box.classList || !box.classList.contains('bulk-check')) { return; }
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.bulk-check'));
    if (ev.shiftKey && lastChecked) {
      var a = boxes.indexOf(lastChecked);
      var b = boxes.indexOf(box);
      if (a > -1 && b > -1) {
        boxes.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (n) {
          n.checked = box.checked;
        });
      }
    }
    lastChecked = box;
    refreshBulk();
  });

  var bulkClear = document.getElementById('bulk-clear');
  if (bulkClear) {
    bulkClear.addEventListener('click', function () {
      document.querySelectorAll('.bulk-check:checked').forEach(function (n) { n.checked = false; });
      refreshBulk();
    });
  }

  refreshBulk();

  // ---------------------------------------------------------------
  // Admin grids: one Save button for the whole table
  //
  // Every editable cell reports to a single form. This watches for
  // changes, counts the affected rows, and wakes the save bar up so a
  // typed edit cannot be walked away from unnoticed. With JavaScript
  // off, the Save button is simply always live and still works.
  // ---------------------------------------------------------------

  var grid = document.querySelector('[data-grid]');
  var savebar = document.querySelector('[data-savebar]');

  if (grid && savebar) {
    var saveBtn = savebar.querySelector('[data-savebar-save]');
    var resetBtn = savebar.querySelector('[data-savebar-reset]');
    var barText = savebar.querySelector('[data-savebar-text]');

    var fields = Array.prototype.slice.call(
      grid.querySelectorAll('input[form], select[form], textarea[form]')
    ).filter(function (el) { return el.type !== 'hidden'; });

    // Remember how each cell started out.
    fields.forEach(function (el) {
      el.dataset.initial = (el.type === 'checkbox') ? String(el.checked) : el.value;
    });

    function isChanged(el) {
      var now = (el.type === 'checkbox') ? String(el.checked) : el.value;
      return now !== el.dataset.initial;
    }

    // Reordering counts as an unsaved change too, so the save bar has to
    // know about the row order as well as the cell values.
    var orderInput = document.querySelector('[data-task-order]');
    function rowIds() {
      return Array.prototype.slice.call(grid.querySelectorAll('[data-row]'))
        .map(function (r) { return r.getAttribute('data-row'); });
    }
    var initialOrder = rowIds();

    function refreshGrid() {
      var dirtyRows = {};

      fields.forEach(function (el) {
        var row = el.closest('[data-row]');
        if (!row) return;
        var id = row.getAttribute('data-row');
        if (isChanged(el)) dirtyRows[id] = true;
      });

      var order = rowIds();
      var moved = order.join(',') !== initialOrder.join(',');

      // Every row that is no longer where it started is a pending change.
      var movedIds = {};
      if (moved) {
        order.forEach(function (id, i) {
          if (initialOrder[i] !== id) { movedIds[id] = true; dirtyRows[id] = true; }
        });
      }

      // Mark the rows themselves so the change is visible in place.
      grid.querySelectorAll('[data-row]').forEach(function (row) {
        var id = row.getAttribute('data-row');
        row.classList.toggle('is-dirty', !!dirtyRows[id] && !movedIds[id]);
        row.classList.toggle('is-moved', !!movedIds[id]);
      });

      if (orderInput) {
        orderInput.value = moved ? order.join(',') : '';
      }

      var n = Object.keys(dirtyRows).length;
      savebar.classList.toggle('is-clean', n === 0);
      saveBtn.disabled = n === 0;
      resetBtn.hidden = n === 0;
      barText.textContent = n === 0
        ? 'No unsaved changes'
        : (n === 1 ? '1 row edited, not saved yet' : n + ' rows edited, not saved yet');

      window.POT_gridDirty = n > 0;
      return n;
    }

    grid.addEventListener('input', refreshGrid);
    grid.addEventListener('change', refreshGrid);

    resetBtn.addEventListener('click', function () {
      fields.forEach(function (el) {
        if (el.type === 'checkbox') {
          el.checked = el.dataset.initial === 'true';
        } else {
          el.value = el.dataset.initial;
        }
      });
      // Put the rows back where they started as well.
      initialOrder.forEach(function (id) {
        var row = grid.querySelector('[data-row="' + id + '"]');
        if (row) grid.appendChild(row);
      });
      refreshGrid();
    });

    // Ctrl+S / Cmd+S saves, the way a spreadsheet would.
    document.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
        if (!saveBtn.disabled) {
          ev.preventDefault();
          saveBtn.click();
        }
      }
    });

    // Leaving with unsaved edits should not be silent.
    window.addEventListener('beforeunload', function (ev) {
      if (window.POT_gridDirty && !window.POT_saving) {
        ev.preventDefault();
        ev.returnValue = '';
      }
    });

    var gridForm = document.getElementById('gridform');
    if (gridForm) {
      gridForm.addEventListener('submit', function () {
        window.POT_saving = true;
        saveBtn.disabled = true;
        barText.textContent = 'Saving...';
      });
    }

    // --- drag a row by its handle to reorder ------------------------
    if (grid.hasAttribute('data-sortable')) {
      var dragging = null;

      function clearMarks() {
        grid.querySelectorAll('.drop-above, .drop-below').forEach(function (r) {
          r.classList.remove('drop-above', 'drop-below');
        });
      }

      // Rows are only draggable while the handle is held, so text in the
      // inputs stays selectable the rest of the time.
      grid.querySelectorAll('.drag-handle').forEach(function (handle) {
        var row = handle.closest('[data-row]');
        if (!row) return;
        handle.addEventListener('mousedown', function () { row.draggable = true; });
        row.addEventListener('mouseup', function () { row.draggable = false; });
      });

      grid.addEventListener('dragstart', function (ev) {
        var row = ev.target.closest && ev.target.closest('[data-row]');
        if (!row || !row.draggable) { ev.preventDefault(); return; }
        dragging = row;
        row.classList.add('dragging');
        ev.dataTransfer.effectAllowed = 'move';
        try { ev.dataTransfer.setData('text/plain', row.getAttribute('data-row')); } catch (e) {}
      });

      grid.addEventListener('dragover', function (ev) {
        if (!dragging) return;
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        clearMarks();
        var over = ev.target.closest && ev.target.closest('[data-row]');
        if (!over || over === dragging) return;
        var box = over.getBoundingClientRect();
        over.classList.add((ev.clientY - box.top) > box.height / 2 ? 'drop-below' : 'drop-above');
      });

      grid.addEventListener('drop', function (ev) {
        if (!dragging) return;
        ev.preventDefault();
        var over = ev.target.closest && ev.target.closest('[data-row]');
        if (over && over !== dragging) {
          var box = over.getBoundingClientRect();
          var after = (ev.clientY - box.top) > box.height / 2;
          over.parentNode.insertBefore(dragging, after ? over.nextSibling : over);
        }
        clearMarks();
        refreshGrid();
      });

      grid.addEventListener('dragend', function () {
        if (dragging) {
          dragging.classList.remove('dragging');
          dragging.draggable = false;
        }
        dragging = null;
        clearMarks();
        refreshGrid();
      });

      // With JavaScript on, the arrows move the row in place instead of
      // posting and reloading, so pending edits are never thrown away.
      // They are also the keyboard and touch path, where dragging is not
      // available.
      grid.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-move]');
        if (!btn) return;
        ev.preventDefault();

        var row = btn.closest('[data-row]');
        if (!row) return;
        var dir = btn.getAttribute('data-move');

        if (dir === 'up') {
          var prev = row.previousElementSibling;
          if (prev && prev.hasAttribute('data-row')) row.parentNode.insertBefore(row, prev);
        } else {
          var next = row.nextElementSibling;
          if (next && next.hasAttribute('data-row')) row.parentNode.insertBefore(next, row);
        }

        refreshGrid();
        var again = row.querySelector('[data-move="' + dir + '"]');
        if (again) again.focus();
      });
    }

    // Buttons that reload the page would throw away pending edits.
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form.hasAttribute || !form.hasAttribute('data-leaves-page')) return;
      if (!window.POT_gridDirty) return;
      var ok = window.confirm(
        'You have unsaved edits in the table below. Doing this now reloads the page and discards them.\n\n' +
        'Continue anyway?'
      );
      if (!ok) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
      } else {
        window.POT_saving = true;
      }
    }, true);

    refreshGrid();
  }

  // ---------------------------------------------------------------
  // Practice detail: one product at a time
  //
  // Every product is rendered, and this shows one. With JavaScript off
  // they all stay visible, which is the old scrolling behaviour rather
  // than a broken page.
  // ---------------------------------------------------------------

  var productTabs = document.querySelector('[data-product-tabs]');

  if (productTabs) {
    var blocks = document.querySelectorAll('[data-product]:not([data-product-tabs])');
    var tabs = productTabs.querySelectorAll('[data-tab]');
    // Remember the choice per practice for the length of the session.
    var memoryKey = 'pot-product-' + (location.search.match(/[?&]p=([a-z\/-]+)/) || [])[1]
                + '-' + ((location.search.match(/[?&]id=(\d+)/) || [])[1] || '');

    function showProduct(which) {
      blocks.forEach(function (b) {
        b.hidden = !(which === 'all' || b.getAttribute('data-product') === which);
      });
      tabs.forEach(function (t) {
        t.classList.toggle('on', t.getAttribute('data-tab') === which);
        t.setAttribute('aria-selected', t.getAttribute('data-tab') === which ? 'true' : 'false');
      });
      // Selections made under another tab would be invisible but still submit.
      var checked = document.querySelectorAll('.bulk-check:checked');
      if (checked.length) {
        checked.forEach(function (c) { c.checked = false; });
        var bar = document.getElementById('bulkbar');
        if (bar) bar.hidden = true;
      }
      try { sessionStorage.setItem(memoryKey, which); } catch (e) {}
    }

    productTabs.addEventListener('click', function (ev) {
      var tab = ev.target.closest('[data-tab]');
      if (tab) showProduct(tab.getAttribute('data-tab'));
    });

    var remembered = null;
    try { remembered = sessionStorage.getItem(memoryKey); } catch (e) {}
    var valid = remembered && (remembered === 'all' ||
      document.querySelector('[data-product="' + remembered + '"]'));

    showProduct(valid ? remembered : (tabs.length ? tabs[0].getAttribute('data-tab') : 'all'));
  }

  // ---------------------------------------------------------------
  // Copy a value to the clipboard, for the shareable link
  // ---------------------------------------------------------------

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-copy]');
    if (!btn) { return; }
    var field = document.querySelector(btn.getAttribute('data-copy'));
    if (!field) { return; }

    field.select();
    field.setSelectionRange(0, 99999);

    var done = function () {
      var was = btn.textContent;
      btn.textContent = 'Copied';
      window.setTimeout(function () { btn.textContent = was; }, 1600);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(field.value).then(done, function () {
        // Older browsers, and any page not served over https.
        try { document.execCommand('copy'); done(); } catch (e) {}
      });
    } else {
      try { document.execCommand('copy'); done(); } catch (e) {}
    }
  });

  // ---------------------------------------------------------------
  // Sign in: let people check what they typed
  // ---------------------------------------------------------------

  var pinPeek = document.querySelector('[data-pin-peek]');
  if (pinPeek) {
    pinPeek.addEventListener('click', function () {
      var input = document.getElementById('pin');
      if (!input) { return; }
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      pinPeek.textContent = showing ? 'Show' : 'Hide';
      pinPeek.setAttribute('aria-label', showing ? 'Show PIN' : 'Hide PIN');
      input.focus();
    });
  }

  // ---------------------------------------------------------------
  // Confirmations on destructive forms
  // ---------------------------------------------------------------

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      ev.preventDefault();
    }
  });

  // Submitting a filter form should drop empty values from the URL.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.classList || !form.classList.contains('filters')) { return; }
    Array.prototype.forEach.call(form.elements, function (el) {
      if (el.name && el.value === '' && el.type !== 'checkbox' && el.type !== 'hidden') {
        el.disabled = true; // a disabled control is not submitted
      }
    });
  });
})();
