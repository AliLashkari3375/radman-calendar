/**
 * ذخیره/حذف AJAX تکی هر ردیف در صفحه «توضیحات و لینک‌ها».
 */
jQuery(function ($) {
	'use strict';

	if (typeof rcAdminManual === 'undefined') {
		return;
	}

	var $table = $('.wp-list-table').first();
	if (!$table.length) {
		return;
	}

	function flash($status, text, color) {
		$status.stop(true, true).show().text(text).css('color', color);
	}

	function fade($status) {
		setTimeout(function () {
			$status.fadeOut(1500, function () {
				$(this).text('').show();
			});
		}, 2000);
	}

	$table.on('click', '.rc-manual-save', function () {
		var $btn = $(this);
		var $row = $btn.closest('.rc-manual-row');
		var $status = $row.find('.rc-manual-status');

		var data = {
			action: 'rc_save_manual_data',
			nonce: rcAdminManual.nonce,
			class_key: $row.attr('data-class-key'),
			description: $row.find('.rc-manual-description').val(),
			link: $row.find('.rc-manual-link').val(),
			priority: $row.find('.rc-manual-priority').val() || 0,
			title: $row.find('.rc-manual-title').val()
		};

		$btn.prop('disabled', true);
		flash($status, 'در حال ذخیره...', '#777');

		$.post(rcAdminManual.ajaxUrl, data)
			.done(function (response) {
				if (response && response.success) {
					flash($status, 'ذخیره شد ✓', 'green');
				} else {
					flash($status, 'خطا: ' + (response && response.data && response.data.message ? response.data.message : 'نامشخص'), 'red');
				}
			})
			.fail(function () {
				flash($status, 'خطا در ارتباط با سرور', 'red');
			})
			.always(function () {
				$btn.prop('disabled', false);
				fade($status);
			});
	});

	$table.on('click', '.rc-manual-delete', function () {
		if (!window.confirm('توضیحات و لینک این ردیف پاک شود؟')) {
			return;
		}

		var $btn = $(this);
		var $row = $btn.closest('.rc-manual-row');
		var $status = $row.find('.rc-manual-status');

		var data = {
			action: 'rc_delete_manual_data',
			nonce: rcAdminManual.nonce,
			class_key: $row.attr('data-class-key')
		};

		$btn.prop('disabled', true);
		flash($status, 'در حال حذف...', '#777');

		$.post(rcAdminManual.ajaxUrl, data)
			.done(function (response) {
				if (response && response.success) {
					$row.find('.rc-manual-description').val('');
					$row.find('.rc-manual-link').val('');
					$row.find('.rc-manual-title').val('');
					$row.find('.rc-manual-priority').val(0);
					flash($status, 'پاک شد ✓', 'green');
				} else {
					flash($status, 'خطا', 'red');
				}
			})
			.fail(function () {
				flash($status, 'خطا در ارتباط با سرور', 'red');
			})
			.always(function () {
				$btn.prop('disabled', false);
				fade($status);
			});
	});
});
