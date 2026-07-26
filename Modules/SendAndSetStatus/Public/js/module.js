(function ($) {
	'use strict';

	// Handles both the primary "Send & Solve" button and the "Send as <status>"
	// items rendered by SendAndSetStatusServiceProvider::renderSendStatusActions().
	// Delegated on document since the Send dropdown is re-rendered per
	// conversation load.
	$(document).on('click', '.sas-send-status', function (e) {
		e.preventDefault();

		var status = $(this).attr('data-send-status');

		// Same scoping main.js's own prepareReplyForm() uses for this select —
		// there is one shared Status <select>, its enclosing block's classes
		// (conv-note-block/conv-forward-block) change with the editor's mode,
		// not the select itself.
		$('.conv-reply-block').children().find(":input[name='status']:first").val(status);

		// Same selector main.js's own Cmd+Enter send shortcut uses to find
		// whichever of the Send/Forward/Note/Create buttons is currently
		// visible for the active editor mode.
		var button = $('div.conv-reply-body:visible .btn-reply-submit:first');
		if (button.length) {
			button.click();
		}
	});
})(jQuery);
