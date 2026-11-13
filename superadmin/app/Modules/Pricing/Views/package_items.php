<?php
/**
 * Checkboxes of the items a package can bundle.
 *
 * @var array<string, array<string, mixed>> $items  pricing_items
 * @var list<string>                        $chosen website / multisite / module codes in the package
 */
$opts = ['website' => 'Website & online store', 'multisite' => 'Multi-outlet website'];
foreach ($items as $i) {
    if ($i['kind'] === 'module' && ! (bool) $i['in_base']) {
        $opts[(string) $i['module_code']] = (string) $i['label'];
    }
}
?>
<fieldset class="pkg-items"><legend class="muted">Includes</legend>
  <?php foreach ($opts as $code => $label): ?><label class="check"><input type="checkbox" name="items[]" value="<?= esc($code, 'attr') ?>" <?= checked(in_array($code, $chosen, true)) ?>><?= esc($label) ?></label> <?php endforeach ?>
</fieldset>
