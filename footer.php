<?php
// footer.php - Closes the sidebar layout
?>
            </div> <!-- End page-body -->
        </main> <!-- End page-content -->
    </div> <!-- End page-wrapper -->

    <!-- Tabler JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Sidebar functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                    sidebarOverlay.classList.toggle('mobile-open');
                });
                
                // Close sidebar when clicking overlay
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    sidebarOverlay.classList.remove('mobile-open');
                });
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(e) {
                    if (window.innerWidth < 992 && 
                        !sidebar.contains(e.target) && 
                        !sidebarToggle.contains(e.target) &&
                        sidebar.classList.contains('mobile-open')) {
                        sidebar.classList.remove('mobile-open');
                        sidebarOverlay.classList.remove('mobile-open');
                    }
                });
            }
            
            // Auto-collapse other dropdowns when one opens
            document.querySelectorAll('.nav-link[data-bs-toggle="collapse"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!this.getAttribute('aria-expanded') || this.getAttribute('aria-expanded') === 'false') {
                        // Close other open dropdowns
                        document.querySelectorAll('.nav-link[data-bs-toggle="collapse"]').forEach(otherLink => {
                            if (otherLink !== this && otherLink.getAttribute('aria-expanded') === 'true') {
                                const targetId = otherLink.getAttribute('href');
                                const target = document.querySelector(targetId);
                                if (target) {
                                    target.classList.remove('show');
                                    otherLink.setAttribute('aria-expanded', 'false');
                                }
                            }
                        });
                    }
                });
            });
            
            // Highlight current page in sidebar
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.nav-link, .dropdown-item').forEach(link => {
                if (link.getAttribute('href') && link.getAttribute('href').includes(currentPage)) {
                    link.classList.add('current-page');
                }
            });
        });
        
        // Resize handler
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992) {
                document.getElementById('sidebar').classList.remove('mobile-open');
                document.getElementById('sidebarOverlay').classList.remove('mobile-open');
            }
        });
    </script>
</body>
</html>