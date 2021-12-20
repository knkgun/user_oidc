<p>
	<?php p($_['error']); ?>
</p>
<?php if (isset($_['retryUrl'])) { ?>
<a href="<?php p($_['retryUrl']); ?>" class="button primary">
	<?php p($l->t('Login')); ?>
</a>
<?php } ?>
