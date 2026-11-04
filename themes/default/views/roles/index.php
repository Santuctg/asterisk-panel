<?php require dirname(__DIR__) . '/partials/fields.php';
$permissionGroups = [
    'Core panel' => ['dashboard.view', 'webphone.use'],
    'Telephony' => ['extension.view','extension.manage','voicemail.view','voicemail.manage','device.view','device.manage','ringgroup.view','ringgroup.manage','parking.view','parking.manage','ivr.view','ivr.manage','route.view','route.manage','moh.view','moh.manage','callcenter.view','callcenter.manage','conference.view','conference.manage'],
    'Reports & tools' => ['cdr.view','cdr.delete','call.listen'],
    'People & access' => ['user.view','user.manage','role.view','role.manage','tenant_security.view','tenant_security.manage'],
    'System' => ['system_status.view','system.apply','system_settings.view','system_settings.manage','audit.view'],
];
$groups = [];
foreach ($permissionGroups as $group => $keys) {
    foreach ($keys as $key) if (isset($abilities[$key])) $groups[$group][$key] = $abilities[$key];
}
?>
<div class="page-head"><div><h1>Roles &amp; Permissions</h1><div class="subtitle">Customize panel access for administrators and operators</div></div></div>

<?php if (auth()->can('role.manage')): ?>
<details class="card role-create mb-4"><summary class="card-head role-create-summary"><div><h2>Access roles</h2><p class="card-subtitle">Custom roles can be configured permission by permission.</p></div><span class="btn btn-primary">+ Add role</span></summary>
<form method="post" action="/roles"><div class="card-body"><div class="role-create-name"><?= field_text('name', 'Role name', '', ['required' => true, 'placeholder' => 'e.g. Call supervisor']) ?></div><div class="permission-grid mt-3"><?php foreach ($groups as $group => $items): ?><section class="permission-group"><h3><?= e($group) ?></h3><?php foreach ($items as $ability => $label): ?><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>"><span><?= e($label) ?></span></label><?php endforeach; ?></section><?php endforeach; ?></div></div><div class="card-foot"><button class="btn btn-primary">Create role</button></div><?= csrf() ?></form></details>
<?php else: ?><div class="card role-section-head"><div class="card-head"><div><h2>Access roles</h2><p class="card-subtitle">Roles assigned in this tenant.</p></div></div></div><?php endif; ?>

<div class="role-list">
<?php foreach ($rows as $role): $canEdit = auth()->can('role.manage') && (!$role['built_in'] || auth()->isAdmin()); ?>
<article class="role-row">
    <div class="role-summary"><div class="role-title"><h2><?= e($role['name']) ?></h2><?php if ($role['built_in']): ?><span class="role-badge">BUILT-IN</span><?php endif; ?></div><p class="role-description"><?= $role['built_in'] ? 'Built-in tenant role. Permissions can be adjusted by an Admin.' : 'Custom tenant access role.' ?></p><div class="role-meta"><span><?= count($role['permissions_list']) ?> permissions</span><span><?= (int) $role['user_count'] ?> panel users</span></div></div>
    <div class="role-permissions"><?php if (!$role['permissions_list']): ?><span class="muted">No permissions</span><?php else: foreach ($role['permissions_list'] as $permission): ?><span class="permission-chip"><?= e($permission) ?></span><?php endforeach; endif; ?></div>
    <div class="role-actions"><?php if ($canEdit): ?><details><summary class="btn btn-sm">Edit</summary><form method="post" action="/roles/<?= (int) $role['id'] ?>" class="role-editor"><?= csrf() ?><?php if (!$role['built_in']): ?><?= field_text('name', 'Role name', $role['name'], ['required' => true]) ?><?php endif; ?><?php foreach ($groups as $group => $items): ?><section class="permission-group"><h3><?= e($group) ?></h3><?php foreach ($items as $ability => $label): ?><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>" <?= in_array($ability, $role['permissions_list'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></section><?php endforeach; ?><button class="btn btn-primary">Save permissions</button></form></details><?php else: ?><span class="role-locked">Locked</span><?php endif; ?><?php if (!$role['built_in'] && $canEdit): ?><form method="post" action="/roles/<?= (int) $role['id'] ?>"><?= csrf() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-danger" data-confirm-btn="Delete this role?" <?= (int) $role['user_count'] > 0 ? 'disabled' : '' ?>>Delete</button></form><?php endif; ?></div>
</article>
<?php endforeach; ?>
</div>
