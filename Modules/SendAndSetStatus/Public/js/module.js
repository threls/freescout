(function ($) {
	'use strict';

	// Handles both the primary "Send & Solve" button and the "Send as <status>"
	// items rendered by SendAndSetStatusServiceProvider::renderSendStatusActions().
	// Delegated on document since the Send dropdown is re-rendered per
	// conversation load.
	$(document).on('click', '.sas-send-status', function (e) {
		e.preventDefault();

		var status = $(this).attr('data-send-status');

		// main.js's convEditorInit() clones the whole hidden #editor_bottom_toolbar
		// template into Summernote's own .note-statusbar element on every editor
		// init, so there are two "status" selects in the DOM: the original
		// (never submitted) and this visible clone (the one form.serialize()
		// actually picks up). Same selector the real Send & Close module uses
		// for this exact reason.
		$('.note-statusbar:visible:first select[name="status"]:first').val(status);

		// Same selector main.js's own Cmd+Enter send shortcut uses to find
		// whichever of the Send/Forward/Note/Create buttons is currently
		// visible for the active editor mode.
		var button = $('div.conv-reply-body:visible .btn-reply-submit:first');
		if (button.length) {
			button.click();
		}
	});
})(jQuery);
