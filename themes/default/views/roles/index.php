<?php require dirname(__DIR__) . '/partials/fields.php'; ?>
<div class="page-head">
    <div><h1>Roles</h1><div class="subtitle">Custom permissions for users in the active tenant</div></div>
</div>

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

<div class="card">
    <div class="card-head"><h2>Custom roles</h2></div>
    <div class="card-body tight">
        <?php if (!$rows): ?>
            <?= \App\Core\View::fragment('partials/empty', ['icon'=>'user','title'=>'No custom roles','message'=>'Create a role and select only the permissions it needs.']) ?>
        <?php else: ?>
            <?php foreach ($rows as $role): ?>
                <form method="post" action="/roles/<?= (int) $role['id'] ?>" class="card mb-3">
                    <?= csrf() ?>
                    <div class="card-body">
                        <div class="form-grid">
                            <?= field_text('name', 'Role name', $role['name'], ['required' => true]) ?>
                            <div class="field"><label>Assigned users</label><div><?= (int) $role['user_count'] ?></div></div>
                            <?php foreach ($abilities as $ability => $label): ?>
                                <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($ability) ?>" <?= in_array($ability, $role['permissions_list'], true) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-foot">
                        <button class="btn btn-primary">Save role</button>
                        <button class="btn btn-ghost" formmethod="post" formaction="/roles/<?= (int) $role['id'] ?>" name="_method" value="DELETE" data-confirm-btn="Delete this role?" <?= (int)$role['user_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
