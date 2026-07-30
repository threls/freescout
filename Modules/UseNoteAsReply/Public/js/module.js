(function ($) {
	'use strict';

	// Would opening the reply editor over the top of this lose the agent's
	// own writing? Summernote leaves wrapper markup around an otherwise empty
	// editor, so this compares on text rather than HTML.
	function replyHasContent()
	{
		var body = $('#body').summernote('code');

		if (!body || body === fs_body_default) {
			return false;
		}

		return $.trim($('<div>').html(body).text()).length > 0;
	}

	// Handles the item rendered by UseNoteAsReplyServiceProvider's thread.menu
	// listener. Delegated on document, since threads are re-rendered whenever
	// a conversation loads or a note is edited.
	$(document).on('click', '.unar-use-as-reply', function (e) {
		e.preventDefault();

		var link = $(this);
		var body = link.closest('.thread').find('.thread-content:first').html();

		if (!body) {
			return;
		}

		var editor_open = !$('.conv-reply-block').hasClass('hidden');

		if (editor_open && replyHasContent() && !confirm(link.attr('data-confirm'))) {
			return;
		}

		if (editor_open && !isNote()) {
			// Already replying, so only the text changes. Deliberately not
			// going through showReplyForm() here: that resets the status and
			// assignee selects back to their defaults and clears any
			// attachments, which would undo choices the agent has already made
			// in an open reply.
			setReplyBody(body);
			$('#body').summernote('focus');
			maybeScrollToReplyBlock(0);

			return;
		}

		// Editor closed, or open as a note. prepareReplyForm() only applies
		// when opening from closed — it is the other half of what the Reply
		// button itself does, and running it while a note is open would wipe
		// that note's attachments.
		if (!editor_open) {
			prepareReplyForm();
		}

		// Focuses the editor and scrolls to it on its own.
		showReplyForm({body: body});
	});
})(jQuery);
