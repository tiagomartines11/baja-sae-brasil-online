$(document).ready(function() {
    $(".nav li a").each(function() {
		if ($(this).next().length > 0) {
			$(this).addClass("parent");
		};
	})
	
	$(".toggleMenu").click(function(e) {
		e.preventDefault();
		$(this).toggleClass("active");
		$(".nav").toggle();
	});
	adjustMenu();
})

$(window).bind('resize orientationchange', function() {
	adjustMenu();
});

var adjustMenu = function() {
    var ww = document.body.clientWidth;
    if (ww <= 1024) {
		$(".toggleMenu").css("display", "inline-block");
		if (!$(".toggleMenu").hasClass("active")) {
			$(".nav").hide();
		} else {
			$(".nav").show();
		}
		$(".nav li").unbind('mouseenter mouseleave');
		$(".nav li a.parent").unbind('click').bind('click', function(e) {
			// must be attached to anchor element to prevent bubbling
			e.preventDefault();
			$(this).parent("li").toggleClass("hover");
		});
		$("#full_titulo").hide();
		$("#cell_titulo").show();
		$(".top-header").css('height', '45px').css('padding-top', '25px');
	}
	else if (ww > 768) {
		$(".toggleMenu").css("display", "none");
		$(".nav").show();
		$(".nav li").removeClass("hover");
		$(".nav li a").unbind('click');
		$(".nav li").unbind('mouseenter mouseleave').bind('mouseenter mouseleave', function() {
		 	// must be attached to li so that mouseleave is not triggered when hover over submenu
		 	$(this).toggleClass('hover');
		});
        $("#full_titulo").show();
        $("#cell_titulo").hide();
        $(".top-header").css('height', '70px').css('padding-top', '0px');
	}
}

var selectItem = function (item) {
    item.addClass("current").closest('.first-level').addClass("in-current");
}