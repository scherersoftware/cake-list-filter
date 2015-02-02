<?= $filterBox ?>

<script type="text/javascript">
$(document).ready(function() {
    var $listFilter = $('.list-filter');
    $listFilter.find('.toggle-btn').click(function() {
        $listFilter.find('.panel-body').toggle();
    });
    if($listFilter.hasClass('opened')) {
        $listFilter.find('.panel-body').show();
    }
});
</script>