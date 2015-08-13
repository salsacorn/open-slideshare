<?php if (count($file_list) > 0): ?>
<?php $count = 0; ?>
<?php foreach ($file_list as $file): ?>
<?php $count++; ?>
<?php $u = "https://" . Configure::read('image_bucket_name') . ".s3-". Configure::read('region') . ".amazonaws.com/". $file; ?>
<div style="border:1px solid #000; margin-bottom:0px;">
<?php if ($count <= 2): ?>
        <img class="lazy img-responsive" src="<?php echo $u; ?>" />
<?php else: ?>
        <img class="lazy img-responsive" src="/img/spacer64.png" data-original="<?php echo $u; ?>" />
<?php endif; ?>
</div>
<div style="text-align:right">
<a href="#top"><span class="glyphicon glyphicon-arrow-up" style="color:#bbb"></span></a>
</div>
<?php endforeach; ?>
<?php endif; ?>


<script type="text/javascript">
$1102(function() {
    $1102(".openslideshare_body img.lazy").lazyload({
        threshold : 200,
        effect: "fadeIn"
    });
});
</script>