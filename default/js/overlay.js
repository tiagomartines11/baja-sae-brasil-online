var psTimeout;

window.onload = function() {
 //getStatus();

 //setInterval(getStatus, 1000);

 if (ovl==1) {
  positionScroll();
 } else if (ovl==2) {
  enduroTotals();
 }

}


function positionScroll() {
  $(".positionScroll").removeClass("pshidden");
  $.ajax({
    type: 'POST',
    url: 'overlaydata.php?evt='+evt,
    data: {"order": "pos"},
    success: function(msg) {
      var data = JSON.parse(msg);
      var html = "";
      var i = 1;
      var velocidade = 100;
      data.forEach(function(e) {
        if (e['lap_count'] && e['lap_count']>=0) {
          html += "<div class='scrollPosition'><span>";
          html += i;
          html += "º</span></div>";
          html += "<div class='scrollEquipe'><span>";
          html += e['equipe_id'] + ' - ' + e['equipe'];
          html += "</span></div>";
          i ++;
        }
      });
      $(".positionScrollMarquee").html(html);
      var width = 0
      $(".positionScrollMarquee").children('div').each(function() {
        width += $(this).width();
      })
      width = (Math.ceil(width) + 700);

      $.keyframe.define([{
        name: 'scroll',
        '0%': {'left':'110%'},
        '100%': {'left': (String(-width)+'px') }
      }]);

      var animcss = "scroll "+ String(Math.ceil(width/velocidade)) +"s linear infinite";
      
      $("#psmarquee").css("animation", animcss);

      psTimeout = setTimeout(positionScroll, Math.ceil(width/velocidade)*1000);

    },
    error: function(msg) {
      console.log(msg);

    }
  })
}

function positionScrollDestroy() {
  $(".positionScroll").addClass("pshidden");
  clearTimeout(psTimeout);
  setTimeout(function() {
    $(".positionScrollMarquee").html("");
    $("#psmarquee").css("animation", "");
  }, 500);  
}

function getStatus() {
  $.ajax({
    type: 'POST',
    url: '/getStatus',
    data: {"id": id, "order": "pos"},
    success: function(msg) {
      var data = JSON.parse(msg);
      
      data.forEach(function(e) {
        switch(e['elemento']) {
          case 'positionScroll':
            if (e['status'+id] == 1) {
              positionScroll();
            } else {
              positionScrollDestroy();
            }
            break;
          case 'enduroTotals':
            if (e['status'+id] == 1) {
              enduroTotals();
            } else {
              enduroTotalsDestroy();
            }
            break;
        }
      });

    },
    error: function(msg) {
      console.log(msg);

    }
  });
}

function enduroTotals() {
  $(".enduroTotals").removeClass("ethidden");
  $.ajax({
    type: 'POST',
    url: 'overlaydata.php?evt='+evt,
    data: {"order": "pos"},
    success: function(msg) {
      var data = JSON.parse(msg);
      var html = "";
      var pos = 1;
      data.forEach(function(e) {
        if (e['lap_count'] && e['lap_count']>=0) {
          html += "<div class='etLine'><div class='etPos'><span>";
          html += pos;
          html += "º</span></div><div class='etNum'><span>";
          html += e['equipe_id'];
          html += "</span></div><div class='etMiddle'><span class='etEq'>";
          html += (e['equipe'] + " - " + e['escola']);
          html += "</span><br><span class='etCid'>";
          html += (e['cidade'] + " - " + e['estado'] + "&nbsp;");
          html += ("<img class='etFlag'><img src='img/" + e['estado'].toLowerCase() + ".png'>");
          html += "</span></div><div class='etVts'><span>";
          html += e['lap_count'];
          html += "</span></div><hr></div>";
          pos++;
        }
      });
      $("#enduroTotals").html(html);
      var height = 0;
      $("#enduroTotals").children('div').each(function() {
        height += $(this).height();
      })

    },
    error: function(msg) {
      console.log(msg);
    }
  });

  etTimeout = setTimeout(enduroTotals, 5000);
}

function enduroTotalsDestroy() {
  $(".enduroTotals").addClass("ethidden");
}