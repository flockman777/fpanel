<script>
document.addEventListener('alpine:initialized', function(){
  var fix = function(){ var f = window.fpanelIcons || window.lucide; f && f.createIcons && f.createIcons(); };
  fix(); setTimeout(fix, 60); setTimeout(fix, 300);
  document.addEventListener('htmx:afterSwap', fix);
  document.addEventListener('htmx:afterSettle', fix);
});
</script>
</body>
</html>
