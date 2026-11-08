<?php
require dirname(__DIR__) . '/partials/fields.php';
$isNew = empty($row);
$action = $isNew ? '/contacts' : '/contacts/' . (int) $row['id'];
$val = static fn (string $key, string $default = ''): string => (string) ($row[$key] ?? old($key, $default));
?>
<div class="page-head"><div><h1><?= $isNew ? 'New contact' : 'Edit contact' ?></h1><div class="subtitle">Tenant phonebook entries are shown to Web Phone users in this tenant.</div></div><div class="page-actions"><a href="/contacts" class="btn"><?= $this->fragment('icons/chevron') ?> Back to contacts</a></div></div>
<form method="post" action="<?= e($action) ?>"><?= csrf() ?>
    <div class="card"><div class="card-head"><h2>Contact details</h2></div><div class="card-body"><div class="form-grid">
        <?= field_text('name', 'Name', $val('name'), ['required' => true, 'placeholder' => 'e.g. Sarah Ahmed']) ?>
        <?= field_text('phone', 'Phone number', $val('phone'), ['required' => true, 'type' => 'tel', 'placeholder' => '+8801712345678', 'hint' => 'Digits and an optional leading +. Formatting spaces and dashes are removed.']) ?>
        <?= field_text('email', 'Email address', $val('email'), ['type' => 'email']) ?>
        <?= field_text('company', 'Company', $val('company')) ?>
        <?= field_textarea('notes', 'Notes', $val('notes'), ['rows' => 4, 'span' => true]) ?>
    </div></div><div class="card-foot"><a href="/contacts" class="btn">Cancel</a><button type="submit" class="btn btn-primary"><?= $this->fragment('icons/check') ?> <?= $isNew ? 'Add contact' : 'Save contact' ?></button></div></div>
</form>
