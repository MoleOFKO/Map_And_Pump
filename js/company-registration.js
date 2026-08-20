document.querySelectorAll('.upload-field input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
        const label = document.getElementById(`${input.id}-label`);
        if (label && input.files.length) label.textContent = input.files[0].name;
    });
});

document.getElementById('company-registration-form')?.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!event.currentTarget.checkValidity()) {
        event.currentTarget.classList.add('was-validated');
        return;
    }

    document.getElementById('registration-form-status').textContent =
        'Your details are ready. Registration submission will be connected once the approval workflow is confirmed.';
});
