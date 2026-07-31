(function () {
	if (typeof macaBackupProPlugins === 'undefined') {
		return;
	}

	var slug = macaBackupProPlugins.pluginSlug;
	var row = document.querySelector('tr[data-plugin="' + slug + '"]');
	if (!row) {
		return;
	}

	var deactivateLink = row.querySelector('span.deactivate a');
	if (!deactivateLink) {
		return;
	}

	var modal = null;

	function closeModal() {
		if (modal && modal.parentNode) {
			modal.parentNode.removeChild(modal);
		}
		modal = null;
	}

	function reportDeactivation(reason, details, callback) {
		var params = new URLSearchParams();
		params.append('action', 'maca_backup_pro_deactivation_feedback');
		params.append('nonce', macaBackupProPlugins.nonce);
		if (reason) {
			params.append('reason', reason);
		}
		if (details) {
			params.append('details', details);
		}

		fetch(macaBackupProPlugins.ajaxUrl, {
			method: 'POST',
			body: params,
			credentials: 'same-origin',
			keepalive: true,
		}).finally(callback);
	}

	function proceedDeactivate(url, reason, details) {
		reportDeactivation(reason, details, function () {
			window.location.href = url;
		});
	}

	function openModal(deactivateUrl) {
		closeModal();

		modal = document.createElement('div');
		modal.className = 'maca-backup-pro-deactivate-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-labelledby', 'maca-backup-pro-deactivate-title');

		var reasons = macaBackupProPlugins.reasons || [];
		var optionsHtml = reasons.map(function (reason, index) {
			return (
				'<label class="maca-backup-pro-deactivate-modal__option">' +
				'<input type="radio" name="maca_backup_pro_deactivate_reason" value="' + reason.id + '"' +
				(index === 0 ? ' checked' : '') + '> ' +
				reason.label +
				'</label>'
			);
		}).join('');

		modal.innerHTML =
			'<div class="maca-backup-pro-deactivate-modal__backdrop" data-action="cancel"></div>' +
			'<div class="maca-backup-pro-deactivate-modal__dialog">' +
			'<h2 id="maca-backup-pro-deactivate-title" class="maca-backup-pro-deactivate-modal__title">' +
			macaBackupProPlugins.modalTitle +
			'</h2>' +
			'<p class="maca-backup-pro-deactivate-modal__intro">' + macaBackupProPlugins.modalIntro + '</p>' +
			'<div class="maca-backup-pro-deactivate-modal__options">' + optionsHtml + '</div>' +
			'<textarea class="maca-backup-pro-deactivate-modal__details" rows="3" placeholder="' +
			macaBackupProPlugins.detailsPlaceholder +
			'"></textarea>' +
			'<div class="maca-backup-pro-deactivate-modal__actions">' +
			'<button type="button" class="button" data-action="cancel">' + macaBackupProPlugins.cancelLabel + '</button>' +
			'<button type="button" class="button" data-action="skip">' + macaBackupProPlugins.skipLabel + '</button>' +
			'<button type="button" class="button button-primary" data-action="submit">' +
			macaBackupProPlugins.submitLabel +
			'</button>' +
			'</div>' +
			'</div>';

		document.body.appendChild(modal);

		var detailsField = modal.querySelector('.maca-backup-pro-deactivate-modal__details');
		var radios = modal.querySelectorAll('input[name="maca_backup_pro_deactivate_reason"]');

		function syncDetailsVisibility() {
			var selected = modal.querySelector('input[name="maca_backup_pro_deactivate_reason"]:checked');
			var showDetails = selected && selected.value === 'other';
			detailsField.classList.toggle('is-visible', showDetails);
		}

		radios.forEach(function (radio) {
			radio.addEventListener('change', syncDetailsVisibility);
		});
		syncDetailsVisibility();

		modal.addEventListener('click', function (event) {
			var action = event.target.getAttribute('data-action');
			if (!action) {
				return;
			}

			if (action === 'cancel') {
				event.preventDefault();
				closeModal();
				return;
			}

			if (action === 'skip') {
				event.preventDefault();
				proceedDeactivate(deactivateUrl, '', '');
				return;
			}

			if (action === 'submit') {
				event.preventDefault();
				var selected = modal.querySelector('input[name="maca_backup_pro_deactivate_reason"]:checked');
				var reason = selected ? selected.value : '';
				var details = detailsField.value.trim();
				proceedDeactivate(deactivateUrl, reason, details);
			}
		});
	}

	deactivateLink.addEventListener('click', function (event) {
		event.preventDefault();
		openModal(deactivateLink.href);
	});
})();
