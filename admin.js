document.addEventListener('DOMContentLoaded', function() {
    const toggleButtons = document.querySelectorAll('button[data-target]');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const fieldId = this.dataset.target;
            const field = document.getElementById(fieldId);

            if (field.type === 'password') {
                field.type = 'text';
                this.textContent = 'Hide';
            } else {
                field.type = 'password';
                this.textContent = 'Show';
            }
        });
    });
});
