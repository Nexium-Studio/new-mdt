    </div><!-- /.d-content -->
</main>

<script>
// Toggle sidebar sur mobile
(function(){
    const btns = document.querySelectorAll('[data-toggle-sidebar]');
    btns.forEach(b => b.addEventListener('click', () => {
        document.getElementById('divSidebar')?.classList.toggle('show');
    }));
})();
</script>
</body>
</html>
