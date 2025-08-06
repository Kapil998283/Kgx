// tournament-operations.js - Tournament Management Operations

// Format descriptions for better UX
const formatDescriptions = {
    'Elimination': 'Traditional single-elimination bracket format',
    'Group Stage': 'BMPS-style tournament with multiple groups advancing to finals',
    'Weekly Finals': 'Progressive weekly elimination over multiple weeks',
    'Custom Lobby': 'Single lobby with multiple matches and points accumulation'
};

// Show format description when format is selected
function showFormatDescription(format, context = 'add') {
    const descriptionId = context === 'edit' ? 'edit_format_description' : 'format_description';
    const descriptionElement = document.getElementById(descriptionId);
    if (descriptionElement && formatDescriptions[format]) {
        descriptionElement.textContent = formatDescriptions[format];
    }
}

// Preview banner image from URL
function previewImage(input) {
    const previewImg = input.parentElement.querySelector('.tournament-image-preview');
    if (input.value && input.value.trim() !== '') {
        previewImg.src = input.value;
        previewImg.classList.remove('d-none');
        previewImg.onerror = function() {
            this.classList.add('d-none');
        };
    } else {
        previewImg.classList.add('d-none');
    }
}

// Edit tournament
function editTournament(tournamentId) {
    // Fetch tournament data
    fetch(`common/get_tournament.php?id=${tournamentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tournament = data.tournament;
                const modal = document.getElementById('editTournamentModal');
                
                // Populate form fields
                modal.querySelector('#edit_tournament_id').value = tournament.id;
                modal.querySelector('input[name="name"]').value = tournament.name;
                modal.querySelector('select[name="game_name"]').value = tournament.game_name;
                modal.querySelector('input[name="banner_image"]').value = tournament.banner_image;
                modal.querySelector('input[name="prize_pool"]').value = tournament.prize_pool;
                modal.querySelector('select[name="prize_currency"]').value = tournament.prize_currency;
                modal.querySelector('input[name="entry_fee"]').value = tournament.entry_fee;
                modal.querySelector('input[name="max_teams"]').value = tournament.max_teams;
                modal.querySelector('select[name="mode"]').value = tournament.mode;
                modal.querySelector('select[name="format"]').value = tournament.format;
                modal.querySelector('select[name="match_type"]').value = tournament.match_type;
                modal.querySelector('input[name="registration_open_date"]').value = tournament.registration_open_date;
                modal.querySelector('input[name="registration_close_date"]').value = tournament.registration_close_date;
                modal.querySelector('input[name="playing_start_date"]').value = tournament.playing_start_date;
                modal.querySelector('input[name="finish_date"]').value = tournament.finish_date;
                modal.querySelector('input[name="payment_date"]').value = tournament.payment_date || '';
                modal.querySelector('textarea[name="description"]').value = tournament.description;
                modal.querySelector('textarea[name="rules"]').value = tournament.rules;
                
                // Update format description
                showFormatDescription(tournament.format, 'edit');
                
                // Show preview image
                const bannerInput = modal.querySelector('input[name="banner_image"]');
                previewImage(bannerInput);
                
                // Show modal
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            } else {
                alert('Error loading tournament data: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load tournament data');
        });
}

// Delete tournament
function deleteTournament(id) {
    document.querySelector('#delete_tournament_id').value = id;
    new bootstrap.Modal(document.getElementById('deleteTournamentModal')).show();
}

// View registrations function
function viewRegistrations(tournamentId) {
    const modal = document.getElementById('viewRegistrationsModal');
    const content = document.getElementById('registrationsContent');
    const title = document.getElementById('registrationsModalTitle');
    
    // Store tournament ID in modal for later use
    modal.setAttribute('data-tournament-id', tournamentId);
    
    // Show loading
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    title.textContent = 'Loading...';
    
    // Show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // Fetch registrations
    fetch(`common/get_registrations.php?tournament_id=${tournamentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                title.textContent = `Registered Teams (${data.registrations.length})`;
                
                if (data.registrations.length === 0) {
                    content.innerHTML = '<div class="text-center text-muted"><p>No teams registered yet</p></div>';
                    return;
                }
                
                // Build registrations table
                let html = `
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Team/Player</th>
                                    <th>Registration Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.registrations.forEach(reg => {
                    const statusClass = reg.status === 'approved' ? 'success' : 
                                      reg.status === 'rejected' ? 'danger' : 'warning';
                    
                    html += `
                        <tr>
                            <td>
                                <strong>${reg.team_name || reg.username}</strong>
                                ${reg.team_name ? `<br><small class="text-muted">Leader: ${reg.username}</small>` : ''}
                            </td>
                            <td><small>${new Date(reg.registration_date).toLocaleDateString()}</small></td>
                            <td><span class="badge bg-${statusClass}">${reg.status}</span></td>
                            <td>
                                ${reg.status === 'pending' ? `
                                    <button class="btn btn-sm btn-success" onclick="updateRegistrationStatus(${reg.id}, 'approved')">
                                        <i class="bi bi-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="updateRegistrationStatus(${reg.id}, 'rejected')">
                                        <i class="bi bi-x"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-sm btn-secondary" onclick="updateRegistrationStatus(${reg.id}, 'pending')">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                `}
                            </td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table></div>';
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="alert alert-danger">Error loading registrations: ' + (data.message || 'Unknown error') + '</div>';
                title.textContent = 'Error';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<div class="alert alert-danger">Failed to load registrations</div>';
            title.textContent = 'Error';
        });
}

// Update registration status
function updateRegistrationStatus(registrationId, status) {
    fetch('common/update_registration.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `registration_id=${registrationId}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Refresh the registrations view
            const modal = document.getElementById('viewRegistrationsModal');
            const tournamentId = modal.getAttribute('data-tournament-id');
            if (tournamentId) {
                viewRegistrations(tournamentId);
            } else {
                // Reload the page to refresh tournament counts
                location.reload();
            }
        } else {
            alert('Error updating registration: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update registration status');
    });
}

// Cancel tournament
function cancelTournament(id) {
    document.querySelector('#cancel_tournament_id').value = id;
    new bootstrap.Modal(document.getElementById('cancelTournamentModal')).show();
}

// Smart routing to format-specific management pages
function getTournamentManagementUrl(tournamentId, format) {
    switch(format) {
        case 'Group Stage':
            return `group-stage/tournament-groups.php?id=${tournamentId}`;
        case 'Weekly Finals':
            return `weekly-finals/tournament-phases.php?id=${tournamentId}`;
        case 'Elimination':
        case 'Custom Lobby':
        default:
            return `elimination/tournament-rounds.php?id=${tournamentId}`;
    }
}

