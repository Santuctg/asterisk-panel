<?php require dirname(__DIR__) . '/partials/fields.php'; ?>
<div class="page-head">
    <div><h1>Roles</h1><div class="subtitle">Granular view and management permissions for the active tenant</div></div>
</div>

<?php if(auth()->can('role.manage')): ?>
<form method="post" action="/roles" class="card mb-4">
    <?= csrf() ?>
    <div class="card-head"><h2>Create role</h2></div>
    <div class="card-body">
        <?= field_text('name', 'Role name', '', ['required' => true, 'placeholder' => 'e.g. Call supervisor']) ?>
        <div class="field mt-3"><label>Permissions</label></div>
        <div class="form-grid">
            <?php foreach ($abilities as $ability => $label): ?>
                <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>"> <span><?= e($label) ?></span></label>
            <?php endforeach; ?>
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
                            <?php foreach ($abilities as $ability => $label): ?>
                                <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>" <?= in_array($ability, $role['permissions_list'], true) ? 'checked' : '' ?> <?= $canEdit?'':'disabled' ?>> <span><?= e($label) ?></span></label>
                            <?php endforeach; ?>
                        </div>
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
