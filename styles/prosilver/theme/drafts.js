function updateDraftList(response = false) {
	// Find the draft
	let id = response['draft_id'];
	let date = response['date'];

	// Get first draft, we'll need it for moving elements around
	let first = $('.draft-row').first()
	let draft = $('#draft' + response['draft_id']);

	// Find the draft
	if (draft.length === 0) {
		// Draft not found, add new draft to the list

		// Clone template
		draft = document.getElementById('draft-template').content.firstElementChild.cloneNode(true)

		// Set some data
		$(draft).attr('id', 'draft' + id);
		$(draft).find('.topictitle')[0].innerHTML = subjectDraft
		$(draft).find('.draft-date').each(function() {
			this.innerHTML = date;
		})
		$(draft).find('[data-id]').each(function() {
			$(this).attr('data-id', id)
		})

		// Prepare new url
		let viewURL = updateURL(routing.attr('data-view'));
		$(draft).find('.view-draft').attr('href', viewURL)

		let deleteURL = updateURL(routing.attr('data-delete'));
		$(draft).find('.delete-draft').attr('href', deleteURL)

		// Setup events
		$(draft).find(".view-draft").on('click.ctc',
			/** @param {MouseEvent} event */
			function(event) {
				event.preventDefault();
				let url = event.target.getAttribute('href');
				window.open(url, 'draftPreview', 'popup=true,width=640,height=480');
			});

		$(draft).find('.delete-draft').each(function() {
			phpbb.ajaxify({
				selector: this,
				refresh: false,
				filter: false,
				callback: 'ctc.draft_remove'
			});
		});

		// Add draft to list
		if (first.length !== 0) {
			first.before(draft);
		}

		// Empty list, add to list wrapper
		else {
			$('#draft-list').append(draft);
		}

		// Update styles
		$(draft).addClass('current-draft');
		updateBackgrounds();
		updateDraftCounts();
	}
	// Draft found, do things
	else {
		// Move to first spot if not already there
		if (draft.attr('id') !== first.attr('id')) {
			// Detach and move to the first spot
			draft.detach();
			first.before(draft);

			// Update styles
			updateBackgrounds();
		}

		// Update date
		draft.find('.draft-date').each(function() {
			this.innerHTML = date;
		})

		// Update subject
		let currentSubject = draft.find('.topictitle')
		if (currentSubject.text() !== subjectDraft){
			currentSubject.text(subjectDraft)
		}
	}

	// Add/update hidden input field to carry draft id between page views
	let input = $('input[name="draft_loaded"]');
	if (input.length === 0) {
		$('.submit-buttons').prepend('<input type="hidden" name="draft_loaded" value="' + id + '">');
	} else {
		input.attr('value', id);
	}
}

function updateBackgrounds() {
	// Fix backgrounds
	$('.draft-row:even').addClass('bg2').removeClass('bg1');
	$('.draft-row:odd').addClass('bg1').removeClass('bg2');
}

function deleteDraft(id) {
	$("#draft" + id).remove();
	if (draftID === id) {
		draftID = 0;
		history.replaceState(null, '', updateURL(location.href))
	}
	updateDraftCounts();
}

function updateDraftCounts() {
	$("#draft-count").text($(".draft-row").length);
}

function updateURL(url) {
	// Prepare new url
	let link = new URL(url);
	let params = new URLSearchParams(link.search)
	params.set('d', draftID)
	link.search = "?" + params.toString()
	return link.href
}

function autodraft() {
	// Todo: Use async?
	// Todo: Offline draft saving?
	if (messageElement.value !== messageDraft || subjectElement.value !== subjectDraft) {
		// Todo: deal with connection problems
		subjectDraft = subjectElement.value
		messageDraft = messageElement.value

		// Prep data
		let postData = {
			subject: subjectDraft,
			message: messageDraft,
			f: forumID,
			t: topicID,
			p: postID,
			mode: mode,
			d: draftID,
			creation_time: $('[name="creation_time"]').attr('value'),
			form_token: $('[name="form_token"]').attr('value'),
		}

		// Save draft
		$.post(routing.attr('data-save'), postData, function(response) {
			if ('error' in response) {
				// Todo: finish error messages
			}
			if ('draft_id' in response) {
				draftID = Number(response['draft_id']);
				updateDraftList(response);

				// Push draft id to the current page's history. This helps to recover from page refreshing
				history.replaceState(null, '', updateURL(location.href))
			}
			if ('topic_id' in response) {
				// Todo: not needed?
				topicID = response['topic_id'];
			}
		}, 'json')
	}
}

/** @type {HTMLInputElement} */
let subjectElement = document.getElementById('subject')
let subjectDraft = subjectElement.value

/** @type {HTMLTextAreaElement} */
let messageElement = document.getElementById('message')
let messageDraft = messageElement.value;

// Get params from URL
let params = new URLSearchParams(document.location.search);
let forumID = params.get('f');
let topicID = params.get('t');
let postID = params.get('p');
let mode = params.get('mode');

// Try to get id from parameter or element
let draftID = Number(params.get('d'));
if(draftID === 0){
	let draft_loaded = $('[name="draft_loaded"]')
	if(draft_loaded.length === 1){
		draftID = Number($('[name="draft_loaded"]').attr('value'))
	}
}

// Grab URLs from routing element
let routing = $('#draft-routing');

// Set up repeating timer to save drafts
let draftTimer = setInterval(autodraft, 10000)


// Todo: Get settings via GET

// Setup draft links
$(".view-draft").on('click.ctc',
	/** @param {MouseEvent} event */
	function(event) {
		event.preventDefault();
		let url = event.target.getAttribute('href');
		window.open(url, 'draftPreview', 'popup=true,width=640,height=480');
	});

phpbb.addAjaxCallback('ctc.draft_remove', function(response) {
	deleteDraft(response['draft_id'])
});
