<?php require dirname(__DIR__) . '/partials/fields.php'; ?>
<div class="page-head">
    <div><h1>Roles</h1><div class="subtitle">Granular view and management permissions for the active tenant</div></div>
</div>

<?php
$permissionGroups = [
    'Core panel' => ['dashboard.view', 'webphone.use'],
    'Telephony' => ['extension.view','extension.manage','voicemail.view','voicemail.manage','device.view','device.manage','ringgroup.view','ringgroup.manage','parking.view','parking.manage','ivr.view','ivr.manage','route.view','route.manage','moh.view','moh.manage','callcenter.view','callcenter.manage','conference.view','conference.manage'],
    'Reports & tools' => ['cdr.view','cdr.delete','call.listen'],
    'People & access' => ['user.view','user.manage','role.view','role.manage','tenant_security.view','tenant_security.manage'],
    'System' => ['system_status.view','system.apply','system_settings.view','system_settings.manage','audit.view'],
];
$grouped = static function (array $abilities) use ($permissionGroups): array {
    $out = [];
    foreach ($permissionGroups as $group => $keys) {
        $items = [];
        foreach ($keys as $key) if (isset($abilities[$key])) $items[$key] = $abilities[$key];
        if ($items) $out[$group] = $items;
    }
    return $out;
};
$abilityGroups = $grouped($abilities);
?>

<?php if(auth()->can('role.manage')): ?>
<form method="post" action="/roles" class="card mb-4">
    <?= csrf() ?>
    <div class="card-head"><h2>Create role</h2></div>
    <div class="card-body">
        <?= field_text('name', 'Role name', '', ['required' => true, 'placeholder' => 'e.g. Call supervisor']) ?>
        <div class="field mt-3"><label>Permissions</label></div>
        <div class="grid grid-2">
            <?php foreach ($abilityGroups as $group => $items): ?><section class="permission-group"><h3><?= e($group) ?></h3><?php foreach ($items as $ability => $label): ?><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>"> <span><?= e($label) ?></span></label><?php endforeach; ?></section><?php endforeach; ?>
        </div>
    </div>
    <div class="card-foot"><button class="btn btn-primary">Create role</button></div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Admin, User and custom roles</h2></div>
    <div class="card-body tight">
        <?php if (!$rows): ?>
            <?= \App\Core\View::fragment('partials/empty', ['icon'=>'user','title'=>'No custom roles','message'=>'Create a role and select only the permissions it needs.']) ?>
        <?php else: ?>
            <?php foreach ($rows as $role): ?>
                <?php $canEdit=auth()->can('role.manage')&&(!$role['built_in']||auth()->isAdmin()); ?>
                <form method="post" action="/roles/<?= (int) $role['id'] ?>" class="card mb-3">
                    <?= csrf() ?>
                    <div class="card-body">
                        <div class="form-grid">
                            <?= field_text('name', 'Role name', $role['name'], ['required' => true,'readonly'=>$role['built_in']||!$canEdit]) ?>
                            <div class="field"><label>Assigned users</label><div><?= (int) $role['user_count'] ?></div></div>
                        </div>
                        <div class="grid grid-2 mt-3"><?php foreach ($abilityGroups as $group => $items): ?><section class="permission-group"><h3><?= e($group) ?></h3><?php foreach ($items as $ability => $label): ?><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>" <?= in_array($ability, $role['permissions_list'], true) ? 'checked' : '' ?> <?= $canEdit?'':'disabled' ?>> <span><?= e($label) ?></span></label><?php endforeach; ?></section><?php endforeach; ?></div>
                    </div>
                    <div class="card-foot">
                        <?php if($canEdit): ?><button class="btn btn-primary">Save role</button><?php endif; ?>
                        <?php if(!$role['built_in']&&$canEdit): ?><button class="btn btn-ghost" formmethod="post" formaction="/roles/<?= (int) $role['id'] ?>" name="_method" value="DELETE" data-confirm-btn="Delete this role?" <?= (int)$role['user_count'] > 0 ? 'disabled' : '' ?>>Delete</button><?php endif; ?>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
