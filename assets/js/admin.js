(function ($) {
	'use strict';

	var cfg = window.macaBackupPro || {};
	cfg.i18n = cfg.i18n || {};
	var i18n = cfg.i18n;
	var pollTimer = null;
	var elapsedTimer = null;
	var jobStartedAt = 0;
	var lastProgressData = null;
	var activeJobId = cfg.activeJob && cfg.activeJob.id ? parseInt(cfg.activeJob.id, 10) : 0;
	var treeSelections = { restore: {}, smart: {} };
	var smartState = null;
	var smartBrowseMode = false;

	function t(key, fallback) {
		return (i18n && i18n[key]) || fallback || '';
	}

	function legalOk() {
		return cfg.legalAccepted !== false && cfg.legalAccepted !== 0 && cfg.legalAccepted !== '0';
	}

	function requireLegal() {
		if (legalOk()) {
			return true;
		}
		window.alert(t('legalRequired', 'Please accept the Terms and Privacy Policy first.'));
		if (cfg.supportUrl) {
			window.location.href = cfg.supportUrl;
		}
		return false;
	}

	function applyLegalGate() {
		if (legalOk()) {
			return;
		}
		$('.maca-bp-btn, #maca-bp-run-restore, #maca-bp-smart-restore')
			.prop('disabled', true)
			.attr('title', t('legalRequired', ''));
	}

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return $.ajax({
			url: cfg.ajaxUrl || window.ajaxurl || '',
			method: 'POST',
			dataType: 'json',
			data: data
		});
	}

	function $progress() {
		return $('#maca-bp-progress');
	}

	function setStartButtonsDisabled(disabled) {
		$('.maca-bp-btn[data-type], #maca-bp-start-full, #maca-bp-start-db, #maca-bp-start-files').prop('disabled', !!disabled);
	}

	function showProgress(show) {
		var $el = $progress();
		if (!$el.length) {
			return;
		}
		$el.prop('hidden', !show);
		$el.toggleClass('is-active', !!show);
		if (!show) {
			$el.removeClass('has-fill');
			$el.find('.maca-bp-progress__stop').prop('hidden', true);
			$el.find('.maca-bp-progress__bar span').css('width', '0%');
			$el.find('.maca-bp-progress__detail').text('');
			$el.find('.maca-bp-progress__elapsed').text('');
			$el.find('.maca-bp-progress__note').prop('hidden', true);
			$el.find('.maca-bp-progress__label').text('');
			stopElapsedClock();
			lastProgressData = null;
		} else {
			$el.find('.maca-bp-progress__stop').prop('hidden', false);
			$el.find('.maca-bp-progress__note').prop('hidden', false);
		}
	}

	function formatElapsed(sec) {
		sec = Math.max(0, Math.floor(sec || 0));
		var h = Math.floor(sec / 3600);
		var m = Math.floor((sec % 3600) / 60);
		var s = sec % 60;
		if (h > 0) {
			return h + 'h ' + m + 'm ' + s + 's';
		}
		if (m > 0) {
			return m + 'm ' + s + 's';
		}
		return s + 's';
	}

	function elapsedSeconds() {
		if (!jobStartedAt) {
			return 0;
		}
		return Math.max(0, Math.floor(Date.now() / 1000) - jobStartedAt);
	}

	function startElapsedClock(startedUnix) {
		var s = parseInt(startedUnix, 10) || 0;
		if (s > 0) {
			jobStartedAt = s;
		} else if (!jobStartedAt) {
			jobStartedAt = Math.floor(Date.now() / 1000);
		}
		if (elapsedTimer) {
			return;
		}
		elapsedTimer = window.setInterval(function () {
			if (lastProgressData) {
				setProgress(
					lastProgressData.progress || 0,
					formatProgressLabel(lastProgressData),
					formatProgressDetail(lastProgressData),
					true
				);
			} else {
				renderElapsed();
			}
		}, 1000);
	}

	function stopElapsedClock() {
		if (elapsedTimer) {
			window.clearInterval(elapsedTimer);
			elapsedTimer = null;
		}
		jobStartedAt = 0;
	}

	function renderElapsed() {
		var $el = $progress().find('.maca-bp-progress__elapsed');
		if (!$el.length) {
			return;
		}
		if (!jobStartedAt) {
			$el.text('');
			return;
		}
		var prefix = t('elapsed', 'Elapsed');
		$el.text(prefix + ': ' + formatElapsed(elapsedSeconds()));
	}

	function setProgress(pct, label, detail, active) {
		var $el = $progress();
		if (!$el.length) {
			return;
		}
		pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
		// Stop stays visible for any truly in-flight job (including 0%), not only mid-fill.
		var running = active !== false && pct < 100;
		$el.toggleClass('is-active', running);
		$el.toggleClass('has-fill', pct > 0 || running);
		$el.find('.maca-bp-progress__stop').prop('hidden', !running);
		$el.find('.maca-bp-progress__bar span').css('width', Math.max(pct, running && pct < 1 ? 2 : pct) + '%');
		if (label) {
			$el.find('.maca-bp-progress__label').text(label);
		}
		var $detail = $el.find('.maca-bp-progress__detail');
		if ($detail.length) {
			$detail.text(detail || '');
		}
		renderElapsed();
	}

	function formatProgressLabel(d) {
		var step = d.step || d.status || '';
		var pct = d.progress || 0;
		var label = step + ' — ' + pct + '%';
		if (d.total > 0) {
			label += ' (' + (d.processed || 0) + ' / ' + d.total + ')';
		}
		return label;
	}

	function formatProgressDetail(d) {
		return d.current_item || '';
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	function onJobFinished(d) {
		activeJobId = 0;
		setStartButtonsDisabled(false);
		stopPolling();
		stopElapsedClock();
		lastProgressData = null;

		if (d.status === 'idle') {
			showProgress(false);
			return;
		}

		if (d.status === 'cancelled') {
			setProgress(d.progress || 0, cfg.i18n.cancelled || 'Cancelled', d.error || '', false);
			$progress().find('.maca-bp-progress__note').prop('hidden', true);
			$progress().find('.maca-bp-progress__stop').prop('hidden', true);
			$progress().removeClass('is-active has-fill');
			return;
		}

		if (d.status === 'failed') {
			setProgress(0, cfg.i18n.failed, d.error || '', false);
			$progress().find('.maca-bp-progress__note').prop('hidden', true);
			$progress().find('.maca-bp-progress__stop').prop('hidden', true);
			$progress().removeClass('is-active has-fill');
			return;
		}

		setProgress(100, cfg.i18n.done, '', false);
		$progress().find('.maca-bp-progress__note').prop('hidden', true);
		$progress().find('.maca-bp-progress__stop').prop('hidden', true);
		$progress().removeClass('is-active');
		$progress().addClass('has-fill');
		window.setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	function statusLoop(jobId) {
		activeJobId = jobId;
		setStartButtonsDisabled(true);
		showProgress(true);
		var seedPct = 0;
		var seedLabel = cfg.i18n.running;
		var seedStarted = 0;
		if (cfg.activeJob && parseInt(cfg.activeJob.id, 10) === parseInt(jobId, 10)) {
			seedPct = parseInt(cfg.activeJob.progress, 10) || 0;
			seedStarted = parseInt(cfg.activeJob.started, 10) || 0;
			if (cfg.activeJob.step) {
				seedLabel = cfg.activeJob.step + ' — ' + seedPct + '%';
			}
		}
		startElapsedClock(seedStarted);
		lastProgressData = { progress: seedPct, step: cfg.activeJob && cfg.activeJob.step ? cfg.activeJob.step : '', status: 'running' };
		setProgress(seedPct, seedLabel, '', true);

		function tick() {
			post('maca_backup_pro_job_status', { job_id: jobId }).done(function (res) {
				if (!res || !res.success) {
					setProgress(0, (res && res.data && res.data.message) || cfg.i18n.failed, '', false);
					setStartButtonsDisabled(false);
					showProgress(false);
					activeJobId = 0;
					return;
				}
				var d = res.data || {};
				if (d.done || d.status === 'completed' || d.status === 'failed' || d.status === 'cancelled') {
					onJobFinished(d);
					return;
				}
				// Idle / no real job — do not keep a fake progress UI open.
				if (d.status === 'idle' || !d.job_id) {
					activeJobId = 0;
					setStartButtonsDisabled(false);
					stopPolling();
					showProgress(false);
					return;
				}
				if (d.started) {
					startElapsedClock(d.started);
				}
				lastProgressData = d;
				setProgress(d.progress || 0, formatProgressLabel(d), formatProgressDetail(d), true);
				pollTimer = window.setTimeout(tick, 600);
			}).fail(function () {
				pollTimer = window.setTimeout(tick, 2500);
			});
		}

		tick();
	}

	function startBackup(type) {
		if (!requireLegal()) {
			return;
		}
		showProgress(true);
		startElapsedClock(Math.floor(Date.now() / 1000));
		lastProgressData = { progress: 1, step: 'starting', status: 'running' };
		setProgress(1, cfg.i18n.starting, '', true);
		setStartButtonsDisabled(true);
		post('maca_backup_pro_start_backup', { type: type || 'full' }).done(function (res) {
			if (!res || !res.success) {
				setProgress(0, (res && res.data && res.data.message) || cfg.i18n.failed, '', false);
				setStartButtonsDisabled(false);
				stopElapsedClock();
				return;
			}
			statusLoop(res.data.job_id);
		}).fail(function () {
			setProgress(0, cfg.i18n.failed, '', false);
			setStartButtonsDisabled(false);
			stopElapsedClock();
		});
	}

	function cancelActiveJob() {
		if (!window.confirm(cfg.i18n.confirmStop || 'Stop the running job?')) {
			return;
		}
		var $btn = $progress().find('.maca-bp-progress__stop');
		$btn.prop('disabled', true);
		post('maca_backup_pro_cancel_job', { job_id: activeJobId || 0 }).done(function (res) {
			$btn.prop('disabled', false);
			if (!res || !res.success) {
				window.alert((res && res.data && res.data.message) || cfg.i18n.failed);
				return;
			}
			onJobFinished(res.data || { status: 'cancelled', done: true, progress: 0 });
		}).fail(function () {
			$btn.prop('disabled', false);
			window.alert(cfg.i18n.failed);
		});
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(str) {
		return escapeHtml(str).replace(/'/g, '&#39;');
	}

	function card(label, value) {
		return '<div class="maca-bp-card"><span class="maca-bp-card__label">' + label + '</span><strong class="maca-bp-card__value">' + (value || 0) + '</strong></div>';
	}

	function selectedPaths(treeKey) {
		return Object.keys(treeSelections[treeKey] || {}).filter(function (p) {
			return treeSelections[treeKey][p];
		});
	}

	function updateSelectedCount(treeKey) {
		var n = selectedPaths(treeKey).length;
		var $el = $('#maca-bp-' + treeKey + '-selected-count');
		if ($el.length) {
			$el.text(n ? (n + ' selected') : '');
		}
		if (treeKey === 'smart') {
			$('#maca-bp-smart-restore').prop('disabled', n === 0 && !$('#maca-bp-smart-db').is(':checked') && !smartBrowseMode);
		}
	}

	function loadTree($root, backupId, prefix, treeKey) {
		$root.addClass('is-loading');
		return post('maca_backup_pro_browse_backup', { backup_id: backupId, prefix: prefix || '' }).done(function (res) {
			$root.removeClass('is-loading');
			if (!res || !res.success) {
				$root.html('<p class="maca-bp-muted">' + escapeHtml((res && res.data && res.data.message) || cfg.i18n.failed) + '</p>');
				return;
			}
			var items = (res.data && res.data.items) || [];
			var html = '<ul class="maca-bp-tree__list">';
			items.forEach(function (item) {
				var checked = treeSelections[treeKey][item.path] ? ' checked' : '';
				var icon = item.type === 'dir' ? '📁' : '📄';
				html += '<li class="maca-bp-tree__item" data-type="' + escapeAttr(item.type) + '" data-path="' + escapeAttr(item.path) + '">';
				html += '<label class="maca-bp-tree__row">';
				html += '<input type="checkbox" class="maca-bp-tree__check" value="' + escapeAttr(item.path) + '"' + checked + ' />';
				if (item.type === 'dir') {
					html += '<button type="button" class="button-link maca-bp-tree__toggle" aria-expanded="false">▸</button>';
				} else {
					html += '<span class="maca-bp-tree__spacer"></span>';
				}
				html += '<span class="maca-bp-tree__icon">' + icon + '</span>';
				html += '<span class="maca-bp-tree__name">' + escapeHtml(item.name) + '</span>';
				if (item.type === 'file' && item.size) {
					html += '<span class="maca-bp-tree__meta">' + escapeHtml(String(item.size)) + '</span>';
				}
				html += '</label>';
				if (item.type === 'dir') {
					html += '<div class="maca-bp-tree__children" hidden></div>';
				}
				html += '</li>';
			});
			html += '</ul>';
			if (!items.length) {
				html = '<p class="maca-bp-muted">Empty</p>';
			}
			$root.html(html);
		}).fail(function () {
			$root.removeClass('is-loading').html('<p class="maca-bp-muted">' + escapeHtml(cfg.i18n.failed) + '</p>');
		});
	}

	function initTree(treeKey, backupId) {
		var $wrap = $('#maca-bp-' + treeKey + '-tree-wrap');
		var $tree = $('#maca-bp-' + treeKey + '-tree');
		treeSelections[treeKey] = {};
		$wrap.prop('hidden', false);
		loadTree($tree, backupId, '', treeKey);
		updateSelectedCount(treeKey);
	}

	$(document).on('change', '.maca-bp-tree__check', function () {
		var $tree = $(this).closest('.maca-bp-tree');
		var treeKey = $tree.data('tree');
		var path = $(this).val();
		treeSelections[treeKey][path] = $(this).is(':checked');
		updateSelectedCount(treeKey);
		if (treeKey === 'smart') {
			$('#maca-bp-smart-restore').prop('disabled', false);
		}
	});

	$(document).on('click', '.maca-bp-tree__toggle', function (e) {
		e.preventDefault();
		var $li = $(this).closest('.maca-bp-tree__item');
		var $children = $li.children('.maca-bp-tree__children');
		var $tree = $(this).closest('.maca-bp-tree');
		var treeKey = $tree.data('tree');
		var path = $li.data('path');
		var backupId = treeKey === 'restore' ? $('#maca-bp-restore-backup').val() : $('#maca-bp-smart-backup').val();
		var expanded = $(this).attr('aria-expanded') === 'true';

		if (expanded) {
			$(this).attr('aria-expanded', 'false').text('▸');
			$children.prop('hidden', true);
			return;
		}

		$(this).attr('aria-expanded', 'true').text('▾');
		$children.prop('hidden', false);
		if (!$children.data('loaded')) {
			loadTree($children, backupId, path, treeKey).done(function () {
				$children.data('loaded', true);
			});
		}
	});

	function toggleRestoreTree() {
		var scope = $('#maca-bp-restore-scope').val();
		var backupId = $('#maca-bp-restore-backup').val();
		if (scope === 'path' && backupId) {
			initTree('restore', backupId);
		} else {
			$('#maca-bp-restore-tree-wrap').prop('hidden', true);
		}
	}

	$('#maca-bp-restore-scope, #maca-bp-restore-backup').on('change', toggleRestoreTree);

	$(document).on('click', '.maca-bp-btn[data-type], #maca-bp-start-full, #maca-bp-start-db, #maca-bp-start-files', function (e) {
		e.preventDefault();
		if ($(this).prop('disabled')) {
			return;
		}
		var type = $(this).data('type') || 'full';
		startBackup(type);
	});

	$(document).on('click', '.maca-bp-progress__stop', function (e) {
		e.preventDefault();
		cancelActiveJob();
	});

	$(document).on('click', '.maca-bp-delete', function (e) {
		e.preventDefault();
		if (!window.confirm(cfg.i18n.confirmDel)) {
			return;
		}
		var id = $(this).data('id');
		var $row = $(this).closest('tr');
		post('maca_backup_pro_delete_backup', { backup_id: id }).done(function (res) {
			if (res && res.success) {
				$row.fadeOut(200, function () {
					$(this).remove();
				});
			}
		});
	});

	function formatBytes(n) {
		n = parseInt(n, 10) || 0;
		if (n < 1024) {
			return n + ' B';
		}
		if (n < 1024 * 1024) {
			return (n / 1024).toFixed(1) + ' KB';
		}
		if (n < 1024 * 1024 * 1024) {
			return (n / (1024 * 1024)).toFixed(1) + ' MB';
		}
		return (n / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
	}

	function renderPathList(title, rows, more, sizeKey) {
		sizeKey = sizeKey || 'size';
		var html = '<div class="maca-bp-compare__list">';
		html += '<h4>' + escapeHtml(title) + ' (' + rows.length + (more ? '+' : '') + ')</h4>';
		if (!rows.length) {
			html += '<p class="maca-bp-muted">—</p></div>';
			return html;
		}
		html += '<ul>';
		rows.forEach(function (row) {
			var meta = '';
			if (typeof row.size_a !== 'undefined') {
				meta = formatBytes(row.size_a) + ' → ' + formatBytes(row.size_b);
			} else {
				meta = formatBytes(row[sizeKey]);
			}
			html += '<li><code>' + escapeHtml(row.path) + '</code><span>' + escapeHtml(meta) + '</span></li>';
		});
		html += '</ul>';
		if (more > 0) {
			html += '<p class="maca-bp-muted">' + escapeHtml((t('compareMore', '…and %d more')).replace('%d', String(more))) + '</p>';
		}
		html += '</div>';
		return html;
	}

	function renderCompareResult(d) {
		var s = d.summary || {};
		var a = d.a || {};
		var b = d.b || {};
		var html = '<div class="maca-bp-compare__cards">';
		html += '<div class="maca-bp-compare__card"><strong>A #' + escapeHtml(String(a.id)) + '</strong>';
		html += '<span>' + escapeHtml(a.date || '') + ' · ' + escapeHtml(a.type || '') + '</span>';
		html += '<span>' + t('compareArchive', 'Archive size') + ': <b>' + formatBytes(a.archive_size) + '</b></span>';
		html += '<span>' + t('compareFiles', 'Files') + ': <b>' + escapeHtml(String(a.file_count || 0)) + '</b></span>';
		html += '<span>' + t('compareContent', 'Content') + ': <b>' + formatBytes(a.content_bytes) + '</b></span></div>';
		html += '<div class="maca-bp-compare__card"><strong>B #' + escapeHtml(String(b.id)) + '</strong>';
		html += '<span>' + escapeHtml(b.date || '') + ' · ' + escapeHtml(b.type || '') + '</span>';
		html += '<span>' + t('compareArchive', 'Archive size') + ': <b>' + formatBytes(b.archive_size) + '</b></span>';
		html += '<span>' + t('compareFiles', 'Files') + ': <b>' + escapeHtml(String(b.file_count || 0)) + '</b></span>';
		html += '<span>' + t('compareContent', 'Content') + ': <b>' + formatBytes(b.content_bytes) + '</b></span></div>';
		html += '</div>';
		html += '<p class="maca-bp-compare__verdict">' + escapeHtml(d.verdict || '') + '</p>';
		html += '<ul class="maca-bp-compare__stats">';
		html += '<li><span>' + t('compareSame', 'Identical paths') + '</span><strong>' + escapeHtml(String(s.same || 0)) + '</strong></li>';
		html += '<li><span>' + t('compareOnlyA', 'Only in A') + '</span><strong>' + escapeHtml(String(s.only_in_a || 0)) + '</strong></li>';
		html += '<li><span>' + t('compareOnlyB', 'Only in B') + '</span><strong>' + escapeHtml(String(s.only_in_b || 0)) + '</strong></li>';
		html += '<li><span>' + t('compareMismatch', 'Size / CRC mismatch') + '</span><strong>' + escapeHtml(String(s.size_mismatch || 0)) + '</strong></li>';
		html += '</ul>';
		var trunc = d.truncated || {};
		html += renderPathList(t('compareOnlyA', 'Only in A'), d.only_in_a || [], trunc.only_in_a || 0);
		html += renderPathList(t('compareOnlyB', 'Only in B'), d.only_in_b || [], trunc.only_in_b || 0);
		html += renderPathList(t('compareMismatch', 'Size / CRC mismatch'), d.size_mismatch || [], trunc.size_mismatch || 0);
		return html;
	}

	$('#maca-bp-compare-run').on('click', function () {
		var idA = parseInt($('#maca-bp-compare-a').val(), 10) || 0;
		var idB = parseInt($('#maca-bp-compare-b').val(), 10) || 0;
		var $out = $('#maca-bp-compare-result');
		var $btn = $(this);
		if (!idA || !idB || idA === idB) {
			window.alert(t('compareNeedTwo', 'Select two different backups to compare.'));
			return;
		}
		$btn.prop('disabled', true);
		$out.prop('hidden', false).html('<p class="maca-bp-muted">' + escapeHtml(t('compareRunning', 'Comparing…')) + '</p>');
		post('maca_backup_pro_compare_backups', { backup_id_a: idA, backup_id_b: idB }).done(function (res) {
			$btn.prop('disabled', false);
			if (!res || !res.success) {
				$out.html('<p class="maca-bp-verify maca-bp-verify--fail"><strong>' +
					escapeHtml((res && res.data && res.data.message) || t('failed', 'Failed')) +
					'</strong></p>');
				return;
			}
			$out.html(renderCompareResult(res.data || {}));
		}).fail(function () {
			$btn.prop('disabled', false);
			$out.html('<p class="maca-bp-verify maca-bp-verify--fail"><strong>' + escapeHtml(t('failed', 'Failed')) + '</strong></p>');
		});
	});

	function renderVerifyResult(res) {
		var i18n = cfg.i18n || {};
		if (!res || !res.success) {
			return '<p class="maca-bp-verify maca-bp-verify--fail"><strong>' +
				((res && res.data && res.data.message) || i18n.failed || 'Failed') +
				'</strong></p>';
		}
		var d = res.data || {};
		var checks = d.checks || {};
		var ok = !!d.ok;
		var rows = [
			['archive_ok', i18n.checkArchive || 'Archive readable'],
			['manifest_ok', i18n.checkManifest || 'Manifest present'],
			['database_ok', i18n.checkDatabase || 'Database dump OK'],
			['files_ok', i18n.checkFiles || 'Files extracted']
		];
		var html = '<div class="maca-bp-verify maca-bp-verify--' + (ok ? 'pass' : 'fail') + '">';
		html += '<p><strong>' + (ok ? (i18n.testPass || 'Passed') : (i18n.testFail || 'Failed')) + '</strong></p>';
		html += '<ul class="maca-bp-verify__list">';
		rows.forEach(function (row) {
			var key = row[0];
			var label = row[1];
			var val = checks[key];
			var state;
			var mark;
			if (val === null || typeof val === 'undefined') {
				state = 'skip';
				mark = i18n.checkSkip || 'N/A';
			} else if (val) {
				state = 'ok';
				mark = 'OK';
			} else {
				state = 'bad';
				mark = 'Fail';
			}
			html += '<li class="maca-bp-verify__item maca-bp-verify__item--' + state + '">' +
				'<span>' + label + '</span><strong>' + mark + '</strong></li>';
		});
		if (checks.file_count) {
			html += '<li class="maca-bp-verify__item"><span>Files</span><strong>' + checks.file_count + '</strong></li>';
		}
		html += '</ul></div>';
		return html;
	}

	function runTestRestore(backupId, $box) {
		if (!backupId) {
			window.alert((cfg.i18n && cfg.i18n.selectBackup) || 'Select a backup first.');
			return;
		}
		$box = $box && $box.length ? $box : $('#maca-bp-preview-box');
		if (!$box.length) {
			$box = $('<div class="maca-bp-preview maca-bp-verify-toast"/>').insertAfter($(document.activeElement).closest('.maca-bp-row-actions, .maca-bp-actions'));
		}
		$box.prop('hidden', false).html('<p>' + ((cfg.i18n && cfg.i18n.testing) || '…') + '</p>');
		post('maca_backup_pro_verify_backup', { backup_id: backupId }).done(function (res) {
			$box.html(renderVerifyResult(res));
		}).fail(function () {
			$box.html(renderVerifyResult(null));
		});
	}

	$('#maca-bp-test-restore').on('click', function () {
		runTestRestore($('#maca-bp-restore-backup').val(), $('#maca-bp-preview-box'));
	});

	$(document).on('click', '.maca-bp-test-restore', function () {
		var id = $(this).data('id');
		var $row = $(this).closest('tr');
		var cols = $row.children('td').length || 8;
		var $box = $row.next('.maca-bp-verify-row').find('.maca-bp-preview');
		if (!$box.length) {
			$row.after('<tr class="maca-bp-verify-row"><td colspan="' + cols + '"><div class="maca-bp-preview"></div></td></tr>');
			$box = $row.next('.maca-bp-verify-row').find('.maca-bp-preview');
		}
		runTestRestore(id, $box);
	});

	$('#maca-bp-preview-restore').on('click', function () {
		var backupId = $('#maca-bp-restore-backup').val();
		var scope = $('#maca-bp-restore-scope').val();
		if (!backupId) {
			return;
		}
		var data = { backup_id: backupId, scope: scope };
		if (scope === 'path') {
			data.paths = selectedPaths('restore');
			if (!data.paths.length) {
				window.alert(cfg.i18n.selectPaths || 'Select at least one file or folder.');
				return;
			}
		}
		var $box = $('#maca-bp-preview-box').prop('hidden', false).text('…');
		post('maca_backup_pro_preview', data).done(function (res) {
			if (!res || !res.success) {
				$box.text((res && res.data && res.data.message) || cfg.i18n.failed);
				return;
			}
			var d = res.data;
			var html = '<p><strong>' + d.file_count + '</strong> files — ' +
				d.overwrite + ' overwrite, ' + d.create + ' create' +
				(d.database ? ', database restore' : '') + '</p><ul class="maca-bp-file-list">';
			(d.files_sample || []).forEach(function (f) {
				html += '<li>' + f.action + ': ' + f.path + '</li>';
			});
			html += '</ul>';
			$box.html(html);
		});
	});

	$('#maca-bp-run-restore').on('click', function () {
		if (!requireLegal()) {
			return;
		}
		var backupId = $('#maca-bp-restore-backup').val();
		var scope = $('#maca-bp-restore-scope').val();
		if (!backupId || !window.confirm(cfg.i18n.confirmRes)) {
			return;
		}
		var data = { backup_id: backupId, scope: scope };
		if (scope === 'path') {
			data.paths = selectedPaths('restore');
			if (!data.paths.length) {
				window.alert(cfg.i18n.selectPaths || 'Select at least one file or folder.');
				return;
			}
		}
		showProgress(true);
		setProgress(1, cfg.i18n.starting, '', true);
		post('maca_backup_pro_start_restore', data).done(function (res) {
			if (!res || !res.success) {
				setProgress(0, (res && res.data && res.data.message) || cfg.i18n.failed, '', false);
				return;
			}
			statusLoop(res.data.job_id);
		});
	});

	$('#maca-bp-smart-compare').on('click', function () {
		var backupId = $('#maca-bp-smart-backup').val();
		if (!backupId) {
			return;
		}
		smartBrowseMode = false;
		$('#maca-bp-smart-tree-wrap').prop('hidden', true);
		$('#maca-bp-smart-summary, #maca-bp-smart-results').prop('hidden', false).html('…');
		post('maca_backup_pro_smart_compare', { backup_id: backupId }).done(function (res) {
			if (!res || !res.success) {
				$('#maca-bp-smart-results').text((res && res.data && res.data.message) || cfg.i18n.failed);
				return;
			}
			smartState = res.data;
			var s = smartState.summary || {};
			$('#maca-bp-smart-summary').html(
				card('New', s.new) + card('Changed', s.changed) + card('Unchanged', s.unchanged) + card('Deleted', s.deleted)
			);

			var html = '<label class="maca-bp-check"><input type="checkbox" id="maca-bp-smart-db" /> Restore database</label>';
			html += '<h3>Changed files</h3><ul class="maca-bp-file-list">';
			(smartState.changed_files || []).forEach(function (f) {
				html += '<li><label><input type="checkbox" class="maca-bp-smart-file" value="' + escapeAttr(f.path) + '" checked /> ' + escapeHtml(f.path) + '</label></li>';
			});
			html += '</ul><h3>New in backup</h3><ul class="maca-bp-file-list">';
			(smartState.new_files || []).forEach(function (f) {
				html += '<li><label><input type="checkbox" class="maca-bp-smart-file" value="' + escapeAttr(f.path) + '" checked /> ' + escapeHtml(f.path) + '</label></li>';
			});
			html += '</ul>';

			if ((smartState.plugin_versions || []).length) {
				html += '<h3>Plugin versions</h3><table class="widefat striped"><thead><tr><th>Slug</th><th>Live</th><th>Backup</th></tr></thead><tbody>';
				smartState.plugin_versions.forEach(function (p) {
					html += '<tr><td>' + escapeHtml(p.slug) + '</td><td>' + escapeHtml(p.live_version || '—') + '</td><td>' + escapeHtml(p.backup_version || '—') + '</td></tr>';
				});
				html += '</tbody></table>';
			}

			$('#maca-bp-smart-results').html(html);
			$('#maca-bp-smart-restore').prop('disabled', !legalOk());
		});
	});

	$('#maca-bp-smart-browse').on('click', function () {
		var backupId = $('#maca-bp-smart-backup').val();
		if (!backupId) {
			return;
		}
		smartBrowseMode = true;
		smartState = { backup_id: parseInt(backupId, 10) };
		$('#maca-bp-smart-summary, #maca-bp-smart-results').prop('hidden', true);
		initTree('smart', backupId);
		$('#maca-bp-smart-restore').prop('disabled', !legalOk());
	});

	$('#maca-bp-smart-restore').on('click', function () {
		if (!requireLegal()) {
			return;
		}
		if (!smartState || !window.confirm(cfg.i18n.confirmRes)) {
			return;
		}
		var files = [];
		if (smartBrowseMode) {
			files = selectedPaths('smart');
			if (!files.length) {
				window.alert(cfg.i18n.selectPaths || 'Select at least one file or folder.');
				return;
			}
		} else {
			$('.maca-bp-smart-file:checked').each(function () {
				files.push($(this).val());
			});
		}
		showProgress(true);
		setProgress(1, cfg.i18n.starting, '', true);
		post('maca_backup_pro_smart_restore', {
			backup_id: smartState.backup_id,
			files: files,
			database: $('#maca-bp-smart-db').is(':checked') ? 1 : 0
		}).done(function (res) {
			if (!res || !res.success) {
				setProgress(0, (res && res.data && res.data.message) || cfg.i18n.failed, '', false);
				return;
			}
			statusLoop(res.data.job_id);
		});
	});

	var resumeStatus = cfg.activeJob && cfg.activeJob.status ? String(cfg.activeJob.status) : '';
	if (
		activeJobId &&
		$progress().length &&
		(resumeStatus === 'pending' || resumeStatus === 'running')
	) {
		statusLoop(activeJobId);
	} else {
		activeJobId = 0;
		if ($progress().length) {
			showProgress(false);
		}
	}

	if ($('#maca-bp-restore-scope').val() === 'path') {
		toggleRestoreTree();
	}

	/* —— Schedule UI (local time; UTC stored server-side) —— */
	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function updateScheduleClock() {
		var hour = parseInt($('#maca-bp-schedule-hour').val(), 10) || 0;
		var minute = parseInt($('#maca-bp-schedule-minute').val(), 10) || 0;
		var freq = $('input[name="schedule"]:checked').val() || 'daily';
		var hourDeg = ((hour % 12) + minute / 60) * 30;
		var minuteDeg = minute * 6;
		$('#maca-bp-clock-hour').css('transform', 'rotate(' + hourDeg + 'deg)');
		$('#maca-bp-clock-minute').css('transform', 'rotate(' + minuteDeg + 'deg)');
		if (freq === 'hourly') {
			$('#maca-bp-preview-local').text(':' + pad2(minute));
		} else {
			$('#maca-bp-preview-local').text(pad2(hour) + ':' + pad2(minute));
		}
	}

	function syncSchedulePanels() {
		var freq = $('input[name="schedule"]:checked').val() || 'daily';
		var hourly = freq === 'hourly';
		var every = freq === 'every_hours';
		var $box = $('#maca-bp-schedule-time-box');
		$('.maca-bp-schedule__freq').removeClass('is-active');
		$('.maca-bp-schedule__freq').has('input[value="' + freq + '"]').addClass('is-active');
		$box.prop('hidden', freq === 'custom');
		$('#maca-bp-schedule-custom-box').prop('hidden', freq !== 'custom');
		$('#maca-bp-schedule-weekday-wrap').prop('hidden', freq !== 'weekly');
		$('#maca-bp-schedule-dom-wrap').prop('hidden', freq !== 'monthly');
		$('#maca-bp-schedule-interval-wrap').prop('hidden', !every);
		$('#maca-bp-schedule-hour-wrap, #maca-bp-schedule-colon, #maca-bp-schedule-clock').prop('hidden', hourly);
		var title = $box.data('title-default') || 'Run time';
		var preview = $box.data('preview-default') || 'Local';
		if (hourly) {
			title = $box.data('title-hourly') || title;
			preview = $box.data('preview-hourly') || preview;
		} else if (every) {
			title = $box.data('title-every') || title;
			preview = $box.data('preview-every') || preview;
		}
		$('#maca-bp-schedule-time-title').text(title);
		$('#maca-bp-preview-label').text(preview);
		updateScheduleClock();
	}

	$(document).on('change', 'input[name="schedule"]', syncSchedulePanels);
	$(document).on('change', '#maca-bp-schedule-hour, #maca-bp-schedule-minute', updateScheduleClock);

	if ($('#maca-bp-schedule-form').length) {
		syncSchedulePanels();
		updateScheduleClock();
	}

	function showSupportMessage(text, isError) {
		var $msg = $('#maca-bp-support-status');
		if (!$msg.length) {
			return;
		}
		$msg.text(text || '').css('color', isError ? '#b32d2e' : '#00a32a');
	}

	var supportBusy = false;

	function submitSupportRequest(event) {
		if (event) {
			event.preventDefault();
			event.stopPropagation();
		}

		if (supportBusy) {
			return false;
		}

		var $form = $('#maca-bp-support-form');
		if (!$form.length) {
			return false;
		}

		var $btn = $('#maca-bp-support-submit');
		var subject = $.trim($('#maca-bp-support-subject').val());
		var message = $.trim($('#maca-bp-support-body').val());
		var email = $.trim($('#maca-bp-support-email').val());
		var nameVal = $('#maca-bp-support-name').val();
		var siteUrl = $('#maca-bp-support-site').val();
		var pluginVersion = $('#maca-bp-support-version').val();

		if (!subject || !message || !email) {
			showSupportMessage(t('supportValidation', 'Please fill in subject, message, and email.'), true);
			return false;
		}

		supportBusy = true;
		$btn.prop('disabled', true);
		showSupportMessage(t('supportSending', 'Sending…'), false);

		post('maca_backup_pro_submit_support', {
			name: nameVal,
			email: email,
			subject: subject,
			message: message,
			include_system_info: $('#maca-bp-support-include-info').is(':checked') ? 1 : 0
		})
			.done(function (res) {
				if (res && res.success) {
					showSupportMessage(
						(res.data && res.data.message) || t('supportSuccess', 'Thank you!'),
						false
					);
					if ($form[0] && typeof $form[0].reset === 'function') {
						$form[0].reset();
					}
					$('#maca-bp-support-name').val(nameVal);
					$('#maca-bp-support-email').val(email);
					$('#maca-bp-support-site').val(siteUrl);
					$('#maca-bp-support-version').val(pluginVersion);
					$('#maca-bp-support-include-info').prop('checked', true);
					return;
				}

				showSupportMessage(
					(res && res.data && res.data.message) || t('supportError', 'Something went wrong.'),
					true
				);
			})
			.fail(function (xhr) {
				var msg = t('supportError', 'Something went wrong.');
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				} else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText && xhr.responseText.charAt(0) === '{') {
					try {
						var parsed = JSON.parse(xhr.responseText);
						if (parsed && parsed.data && parsed.data.message) {
							msg = parsed.data.message;
						}
					} catch (ignore) {
						// Keep fallback message.
					}
				}
				showSupportMessage(msg, true);
			})
			.always(function () {
				supportBusy = false;
				$btn.prop('disabled', false);
			});

		return false;
	}

	$(document).on('submit', '#maca-bp-support-form', submitSupportRequest);
	$(document).on('click', '#maca-bp-support-submit', submitSupportRequest);

	applyLegalGate();

	// —— Onboarding wizard ——
	(function initOnboardingWizard() {
		var $root = $('#maca-bp-onboarding');
		if (!$root.length) {
			return;
		}

		var i18n = {};
		try {
			var raw = $('#maca-bp-onboarding-i18n').text();
			if (raw) {
				i18n = JSON.parse(raw);
			}
		} catch (e) {
			i18n = {};
		}

		var step = 1;
		var total = 4;

		function $panel(n) {
			return $root.find('.maca-bp-wizard__panel[data-step="' + n + '"]');
		}

		function selected(name) {
			return $root.find('input[name="' + name + '"]:checked').val() || '';
		}

		function syncChoiceActive($input) {
			var $group = $input.closest('.maca-bp-wizard__choices');
			$group.find('.maca-bp-wizard__choice').removeClass('is-active');
			$input.closest('.maca-bp-wizard__choice').addClass('is-active');
		}

		function updateStorageHint() {
			var $radio = $root.find('input[name="maca_ob_storage"]:checked');
			var configured = $radio.data('configured') === 1 || $radio.data('configured') === '1' || $radio.val() === 'local';
			var $hint = $('#maca-bp-wiz-storage-hint');
			if (configured) {
				$hint.attr('hidden', 'hidden').prop('hidden', true);
			} else {
				$hint.removeAttr('hidden').prop('hidden', false);
			}
		}

		function updateScheduleVisibility() {
			var mode = selected('maca_ob_mode');
			var show = mode === 'schedule' || mode === 'both';
			var $sched = $('#maca-bp-wiz-schedule');
			if (show) {
				$sched.removeAttr('hidden').prop('hidden', false);
			} else {
				$sched.attr('hidden', 'hidden').prop('hidden', true);
			}
			var weekly = $('#maca-bp-wiz-frequency').val() === 'weekly';
			var $wd = $('#maca-bp-wiz-weekday-wrap');
			if (weekly) {
				$wd.removeAttr('hidden').prop('hidden', false);
			} else {
				$wd.attr('hidden', 'hidden').prop('hidden', true);
			}
		}

		function pad2(n) {
			n = parseInt(n, 10) || 0;
			return (n < 10 ? '0' : '') + n;
		}

		function refreshSummary() {
			var type = selected('maca_ob_type') || 'full';
			var storage = selected('maca_ob_storage') || 'local';
			var mode = selected('maca_ob_mode') || 'now';

			var typeLabel = (i18n.types && i18n.types[type]) || type;
			var storageLabel = (i18n.providers && i18n.providers[storage]) || storage;
			var whenLabel = (i18n.modes && i18n.modes[mode]) || mode;

			if (mode === 'schedule' || mode === 'both') {
				var freq = $('#maca-bp-wiz-frequency').val() || 'daily';
				var hour = pad2($('#maca-bp-wiz-hour').val());
				var minute = pad2($('#maca-bp-wiz-minute').val());
				var freqLabel = freq === 'weekly' ? (i18n.weekly || 'Weekly') : (i18n.daily || 'Daily');
				var dayPart = '';
				if (freq === 'weekly') {
					var wd = String($('#maca-bp-wiz-weekday').val());
					dayPart = ((i18n.weekdays && i18n.weekdays[wd]) || '') + ' ';
				}
				var sched = freqLabel + (dayPart ? ' · ' + dayPart.trim() : '') + ' ' + (i18n.at || 'at') + ' ' + hour + ':' + minute;
				whenLabel = whenLabel + ' — ' + sched;
			}

			$root.find('[data-summary="type"]').text(typeLabel);
			$root.find('[data-summary="storage"]').text(storageLabel);
			$root.find('[data-summary="when"]').text(whenLabel);
		}

		function go(n) {
			step = Math.max(1, Math.min(total, n));
			$root.find('.maca-bp-wizard__panel').each(function () {
				$(this).attr('hidden', 'hidden').prop('hidden', true).removeClass('is-active');
			});
			$panel(step).removeAttr('hidden').prop('hidden', false).addClass('is-active');
			$root.find('.maca-bp-wizard__step').removeClass('is-active is-done');
			$root.find('.maca-bp-wizard__step').each(function () {
				var s = parseInt($(this).attr('data-step-indicator'), 10);
				if (s < step) {
					$(this).addClass('is-done');
				} else if (s === step) {
					$(this).addClass('is-active');
				}
			});
			setNavHidden('#maca-bp-wiz-back', step === 1);
			setNavHidden('#maca-bp-wiz-next', step === total);
			setNavHidden('#maca-bp-wiz-finish', step !== total);
			$('#maca-bp-wiz-error').attr('hidden', 'hidden').prop('hidden', true).text('');
			if (step === 2) {
				updateStorageHint();
			}
			if (step === 3) {
				updateScheduleVisibility();
			}
			if (step === 4) {
				refreshSummary();
			}
		}

		function setNavHidden(sel, hide) {
			var $el = $(sel);
			if (hide) {
				$el.attr('hidden', 'hidden').prop('hidden', true);
			} else {
				$el.removeAttr('hidden').prop('hidden', false);
			}
		}

		function canLeaveStep(n) {
			if (n === 2) {
				var $radio = $root.find('input[name="maca_ob_storage"]:checked');
				var configured = $radio.data('configured') === 1 || $radio.data('configured') === '1' || $radio.val() === 'local';
				if (!configured) {
					updateStorageHint();
					window.alert(i18n.configureFirst || 'Configure this storage destination first, or choose Local storage.');
					return false;
				}
			}
			return true;
		}

		$root.on('change', '.maca-bp-wizard__choice input[type="radio"]', function () {
			syncChoiceActive($(this));
			if ($(this).attr('name') === 'maca_ob_storage') {
				updateStorageHint();
			}
			if ($(this).attr('name') === 'maca_ob_mode') {
				updateScheduleVisibility();
			}
		});

		$('#maca-bp-wiz-frequency').on('change', updateScheduleVisibility);

		$('#maca-bp-wiz-next').on('click', function () {
			if (!canLeaveStep(step)) {
				return;
			}
			go(step + 1);
		});

		$('#maca-bp-wiz-back').on('click', function () {
			go(step - 1);
		});

		$('#maca-bp-wiz-finish').on('click', function () {
			if (!requireLegal()) {
				return;
			}
			if (!canLeaveStep(2)) {
				go(2);
				return;
			}

			var $btn = $(this);
			var mode = selected('maca_ob_mode') || 'now';
			var payload = {
				backup_type: selected('maca_ob_type') || 'full',
				storage_provider: selected('maca_ob_storage') || 'local',
				run_mode: mode,
				schedule_frequency: $('#maca-bp-wiz-frequency').val() || 'daily',
				schedule_hour_local: $('#maca-bp-wiz-hour').val() || '03',
				schedule_minute_local: $('#maca-bp-wiz-minute').val() || '00',
				schedule_weekday: $('#maca-bp-wiz-weekday').val() || '1'
			};

			$btn.prop('disabled', true).text(i18n.finishing || 'Applying…');
			$('#maca-bp-wiz-error').prop('hidden', true).text('');
			$('#maca-bp-wiz-back, #maca-bp-wiz-next').prop('disabled', true);

			post('maca_backup_pro_onboarding_finish', payload).done(function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || i18n.finishFail || 'Could not finish setup.';
					$('#maca-bp-wiz-error').prop('hidden', false).text(msg);
					$btn.prop('disabled', false).text('Finish');
					$('#maca-bp-wiz-back, #maca-bp-wiz-next').prop('disabled', false);
					if (res && res.data && res.data.storage_url) {
						updateStorageHint();
					}
					return;
				}

				$root.slideUp(200, function () {
					$root.remove();
				});

				var jobId = res.data && res.data.job_id ? parseInt(res.data.job_id, 10) : 0;
				if (jobId) {
					statusLoop(jobId);
				} else {
					window.setTimeout(function () {
						window.location.reload();
					}, 400);
				}
			}).fail(function () {
				$('#maca-bp-wiz-error').prop('hidden', false).text(i18n.finishFail || 'Could not finish setup.');
				$btn.prop('disabled', false).text('Finish');
				$('#maca-bp-wiz-back, #maca-bp-wiz-next').prop('disabled', false);
			});
		});

		go(1);
		updateStorageHint();
		updateScheduleVisibility();
	})();

	// Scroll to schedule editor when arriving from legacy onboarding deep-link.
	if (/[?&]onboarding=1(?:&|$)/.test(window.location.search) || window.location.hash === '#maca-bp-schedule-editor') {
		var scheduleEditor = document.getElementById('maca-bp-schedule-editor');
		if (scheduleEditor && scheduleEditor.scrollIntoView) {
			window.setTimeout(function () {
				scheduleEditor.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}, 50);
		}
	}
})(jQuery);
