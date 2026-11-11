<template>
    <div class="d-flex align-items-center gap-3 small text-secondary">
        <span class="d-inline-flex align-items-center gap-1"><span class="cl-swatch cl-answered"></span> Answered</span>
        <span class="d-inline-flex align-items-center gap-1"><span class="cl-swatch cl-failed"></span> Failed</span>
    </div>
</template>

<style scoped>
.cl-swatch {
    display: inline-block;
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 3px;
}
.cl-answered {
    background-color: var(--tblr-primary);
}
.cl-failed {
    background-color: rgba(var(--tblr-primary-rgb), 0.3);
}
</style>
