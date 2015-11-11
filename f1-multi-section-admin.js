jQuery(function($) {
	// Get various values added by PHP so we can use them here in JavaScript land
	var get_admin_url = $('#get_admin_url').val();
	var get_home_url = $('#get_home_url').val();
	var current_id = $('#current_id').val();
	var searchTimer, SelectedChildrenIds = [];

	// Make the list of selected children posts sortable
	$( "#f1-multi-section-children" ).sortable({
		axis: 'y',
		containment: $('#f1-multi-section-report'),
		update: function(event, ui) { getSelectedChildrenIds(); }
	}).disableSelection();

	// On each keypress of the search input, start a timer to fire off an AJAX request for the results aka debouncing...
	$('#f1-multi-section-search input').keypress(function(e){
		var $this = $(this);
		if( 13 == e.which ) {
			updateQuickSearchResults( $this );
			return false;
		}
		if( searchTimer ) {
			clearTimeout(searchTimer);
		}
		searchTimer = setTimeout(function() {
			updateQuickSearchResults( $this );
		}, 400);
	}).attr('autocomplete','off');

	// Perform a search based on date
	$('#f1-multi-section-date-tab a').click(function(e) {
		params = {
			'action': 'multi_section_date',
			'selectedIds': getSelectedChildrenIds(),
			'date': $('#f1-multi-section-date input[type=date]').val(),
			'currentID': current_id
		};
		$( '#f1-multi-section-date img.waiting' ).show();
		$.post( ajaxurl, params, function(result) {
			$('#f1-multi-section-date ul').html(result);
			$( '#f1-multi-section-date img.waiting' ).hide();
		});
	});

	$('#f1-multi-section-date input').keypress(function(e){
		if( 13 == e.which ) {
			$('#f1-multi-section-date-tab a').click();
			return false;
		}
		if( searchTimer ) {
			clearTimeout(searchTimer);
		}
		searchTimer = setTimeout(function(){
			$('#f1-multi-section-date-tab a').click();
		}, 400);
	}).attr('autocomplete','off');

	// Watch for checkbox change events and update elements as needed.
	$('#tax-f1-multi-section').on('change', 'input:checkbox', function() {
		$this = $(this);
		var post_id = $this.val();
		var post_title = $this.parent().text();

		// Strip out any date info...
		post_title = post_title.split(' (')[0];

		if( $this.is(':checked') ){
			$('#no-children').remove();

			var html = '<li>';
				html += '<a href="' + get_admin_url + '/post.php?post=' + post_id + '&action=edit" id="post-' + post_id + '">' + post_title + '</a>';
				html += '<span class="row-actions">';
					html += '<a class="view" href="' + get_home_url + '?p=' + post_id + '" target="_blank">View</a> | ';
					html += '<a class="trash" href="#' + post_id + '">Remove</a>'
				html += '</span>';
			html += '</li>';

			$('#f1-multi-section-children').append( html );
			getSelectedChildrenIds();
			$('#f1-multi-section-report input[name="post-' + post_id + '"]').attr('checked', true);
		} else {
			removeSelectedChildren( post_id );
		}
	});

	// Handles removing a post from the list after clicking on the "trash" link.
	$('#f1-multi-section-children').on('click', '.trash', function(e) {
		var post_id = $(this).attr('href').replace(/#/, '');
		removeSelectedChildren( post_id );
		e.preventDefault();
	});

	function updateQuickSearchResults(input) {
		var minSearchLength = 2;
		var q = input.val();
		if( q.length < minSearchLength ) {
			return;
		}

		$( 'img.waiting', input.parent() ).show();
		var params = {
			'action': 'multi_section_search',
			'selectedIds': getSelectedChildrenIds(),
			'q': q,
			'currentID' : current_id
		};
		$.post( ajaxurl, params, function(result) {
			// Grab the result and inject the returned HTML into the dom.
			$('#f1-multi-section-search ul').html(result);
			$( 'img.waiting', input.parent() ).hide();
		});
	}

	function getSelectedChildrenIds() {
		$('#f1-do-multi-section-save').val('1');
		selectedChildrenIds = [];
		$('#f1-multi-section-children li>a').each(function(index){
			var id = $(this).attr('id').split('-')[1];
			selectedChildrenIds.push( id );
		});
		var val = selectedChildrenIds.join(',')
		$('#f1-selectedIDs').val(val)

		return val;
	}

	function removeSelectedChildren( post_id ) {
		post_id = post_id.toString();

		// Uncheck it
		$('#f1-multi-section-report input[name="post-' + post_id + '"]').attr('checked', false);

		if( $node = $('#f1-multi-section-children #post-' + post_id) ) {
			$node.parent().remove();
			var newIDs = $('#f1-removedIDs').val().toString();
			var pattern = new RegExp(post_id,"gi");

			if( !newIDs.match(pattern) ) {
				if( newIDs == '' ) {
					newIDs = [ post_id ];
				} else {
					newIDs = newIDs.toString().split(',');
					newIDs.push(post_id);
				}
				$('#f1-removedIDs').val( newIDs.join(',') );
			}

			// Since we removed a child post, we need to update the selected children information.
			getSelectedChildrenIds();
		}
	}
});
