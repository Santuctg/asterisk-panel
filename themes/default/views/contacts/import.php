<div class="page-head"><div><h1>Import contacts</h1><div class="subtitle">Upload an Excel-compatible UTF-8 CSV file for the active tenant.</div></div><div class="page-actions"><a href="/contacts" class="btn"><?= $this->fragment('icons/chevron') ?> Back to contacts</a></div></div>
<div class="card contact-import-card">
    <div class="card-head"><h2>CSV format</h2></div>
    <div class="card-body">
        <p>Download the template, fill it in with Excel, then save it as <strong>CSV UTF-8 (Comma delimited)</strong>.</p>
        <div class="contact-import-columns"><code>Name</code><code>Phone</code><code>Email</code><code>Company</code><code>Notes</code></div>
        <p class="hint"><strong>Name</strong> and <strong>Phone</strong> are required. Existing phone numbers in this tenant are skipped. Maximum 1,000 rows and 2 MB.</p>
        <a class="btn" href="/contacts/import/template"><?= $this->fragment('icons/download') ?> Download CSV template</a>
    </div>
</div>
<form method="post" action="/contacts/import" enctype="multipart/form-data"><?= csrf() ?>
    <div class="card"><div class="card-head"><h2>Upload contacts</h2></div><div class="card-body"><div class="field"><label for="contacts_file">CSV file</label><input id="contacts_file" name="contacts_file" type="file" accept=".csv,text/csv" required><span class="hint">Only the selected tenant receives imported contacts.</span></div></div><div class="card-foot"><a href="/contacts" class="btn">Cancel</a><button type="submit" class="btn btn-primary"><?= $this->fragment('icons/download') ?> Import contacts</button></div></div>
</form>
