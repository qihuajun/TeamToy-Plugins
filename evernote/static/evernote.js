function show_evernote(){
	show_float_box( 'Evernote 笔记本' , '?c=plugin&a=evernote_tree' );
}

function evernote_api_save(name){
	var url = $('#'+name).attr('action');
	
	$.each( $('#'+name).serializeArray(), function(index,value) 
	{
		url += "&" + value.name + "=" + encodeURIComponent(value.value);
	});
	
	
	$.get(url, function(data){
		set_form_notice( name , data );
	});
	
}