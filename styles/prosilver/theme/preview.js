$('#close_preview').on('click',function(){window.close();});
$('#load_draft').on('click',function(event){
	window.opener.location = event.target.getAttribute('data-link');
	window.close();
});
