/**
 * صفحه تنطیمات: انتخابگر لوگو (کتابخانه رسانه) و انتخابگر فایل فونت.
 *
 * نسخه ۱.۷.۳ - این کد قبلاً به‌صورت یک تگ <script> درون خروجی HTML صفحه
 * تنطیمات چاپ می‌شد و wp_enqueue_media() هم وسط رندر صفحه صدا زده می‌شد.
 * دو اشکال داشت: (۱) هیچ تضمینی نبود که wp.media قبل از این کد آماده باشد و
 * فقط به شانس setTimeout ضمنی jQuery ready تکیه می‌کرد، (۲) هر بار بازدید
 * صفحه، این جاوااسکریپت دوباره در HTML می‌آمد و هیچ‌وقت توسط مرورگر کش
 * نمی‌شد. حالا یک فایل جدا با وابستگی صریح به media-editor است.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var logoFrame;

		$( '#rc-logo-upload-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( logoFrame ) {
				logoFrame.open();
				return;
			}
			logoFrame = wp.media( {
				title: 'انتخاب لوگو',
				button: { text: 'استفاده از این تصویر' },
				library: { type: 'image' },
				multiple: false
			} );
			logoFrame.on( 'select', function () {
				var attachment = logoFrame.state().get( 'selection' ).first().toJSON();
				$( '#footer_logo_url' ).val( attachment.url );

				// ساخت تگ img به‌صورت DOM (نه رشته HTML) تا نام/آدرس فایل
				// انتخاب‌شده هیچ‌وقت به‌عنوان HTML تفسیر نشود.
				var img = document.createElement( 'img' );
				img.src = attachment.url;
				img.style.cssText = 'max-height:60px;max-width:200px;display:block;border:1px solid #ddd;padding:4px;background:#fff;';
				$( '#rc-logo-preview' ).empty().append( img );

				$( '#rc-logo-remove-btn' ).show();
			} );
			logoFrame.open();
		} );

		$( '#rc-logo-remove-btn' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#footer_logo_url' ).val( '' );
			$( '#rc-logo-preview' ).empty();
			$( this ).hide();
		} );

		// انتخابگر عمومی فایل (برای فایل‌های فونت)
		var fileFrames = {};
		$( '.rc-media-pick' ).on( 'click', function ( e ) {
			e.preventDefault();
			var target = $( this ).data( 'target' );
			if ( fileFrames[ target ] ) {
				fileFrames[ target ].open();
				return;
			}
			fileFrames[ target ] = wp.media( {
				title: 'انتخاب فایل فونت',
				button: { text: 'استفاده از این فایل' },
				multiple: false
			} );
			fileFrames[ target ].on( 'select', function () {
				var file = fileFrames[ target ].state().get( 'selection' ).first().toJSON();
				$( '#' + target ).val( file.url );
			} );
			fileFrames[ target ].open();
		} );
	} );
}( jQuery ) );
