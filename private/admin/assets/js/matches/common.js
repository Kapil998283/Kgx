// Common functionality for match management across all games
(function () {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})();

// Initialize Bootstrap modals - will be set after DOM is loaded
let addMatchModal = null;
let roomDetailsModal = null;
document.addEventListener('DOMContentLoaded', function() {
    addMatchModal = new bootstrap.Modal(document.getElementById('addMatchModal'));
    roomDetailsModal = new bootstrap.Modal(document.getElementById('roomDetailsModal'));
});

// Toggle entry fee based on entry type
function toggleEntryFee() {
    const entryType = document.getElementById('entry_type').value;
    const entryFeeContainer = document.getElementById('entryFeeContainer');
    const entryFeeInput = document.getElementById('entry_fee');
    
    if (entryType === 'free') {
        entryFeeContainer.style.display = 'none';
        entryFeeInput.value = '0';
        entryFeeInput.required = false;
    } else {
        entryFeeContainer.style.display = 'block';
        entryFeeInput.required = true;
    }
}

// Toggle team section
function toggleTeamSection() {
    const enableTeams = document.getElementById('enableTeams');
    const teamSection = document.getElementById('teamSection');
    teamSection.style.display = enableTeams.checked ? 'block' : 'none';
    
    // Clear team selections when disabled
    if (!enableTeams.checked) {
        document.getElementById('team1_id').value = '';
        document.getElementById('team2_id').value = '';
    }
}

// Toggle between real currency and website currency
function togglePrizeCurrency() {
    const useWebsiteCurrency = document.getElementById('useWebsiteCurrency').checked;
    const realCurrencySection = document.getElementById('realCurrencySection');
    const websiteCurrencySection = document.getElementById('websiteCurrencySection');
    
    if (useWebsiteCurrency) {
        realCurrencySection.style.display = 'none';
        websiteCurrencySection.style.display = 'block';
        // Only reset real currency values if they haven't been set yet
        if (!document.getElementById('prize_pool').value) {
            document.getElementById('prize_pool').value = '0';
            document.getElementById('prize_type').value = 'INR';
        }
    } else {
        realCurrencySection.style.display = 'block';
        websiteCurrencySection.style.display = 'none';
        // Only reset website currency values if they haven't been set yet
        if (!document.getElementById('website_currency_amount').value) {
            document.getElementById('website_currency_amount').value = '0';
            document.getElementById('website_currency_type').value = 'coins';
        }
    }
}

// Update website currency label when type changes
document.addEventListener('DOMContentLoaded', function() {
    const websiteCurrencySelect = document.getElementById('website_currency_type');
    if (websiteCurrencySelect) {
        websiteCurrencySelect.addEventListener('change', function() {
            const label = this.value.charAt(0).toUpperCase() + this.value.slice(1);
            const labelElement = document.querySelector('.website-currency-label');
            if (labelElement) {
                labelElement.textContent = label;
            }
        });
    }
});

// Initialize date and time inputs with current values
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    const dateInput = document.getElementById('match_date');
    const timeInput = document.getElementById('match_time');
    
    if (dateInput && timeInput) {
        // Set minimum date to today
        const today = now.toISOString().split('T')[0];
        dateInput.min = today;
        dateInput.value = today;
        
        // Set default time to next hour
        now.setHours(now.getHours() + 1);
        now.setMinutes(0);
        timeInput.value = now.toTimeString().slice(0, 5);
    }

    // Add click handler for Add New Match button
    const addMatchButton = document.getElementById('addMatchButton');
    if (addMatchButton) {
        addMatchButton.addEventListener('click', function() {
            resetMatchForm();
            addMatchModal.show();
        });
    }
});

// Handle match actions
function startMatch(matchId) {
    document.getElementById('room_match_id').value = matchId;
    roomDetailsModal.show();
}

function completeMatch(matchId) {
    if (confirm('Are you sure you want to mark this match as completed?')) {
        submitForm('complete_match', { match_id: matchId });
    }
}

function deleteMatch(matchId) {
    if (confirm('Are you sure you want to delete this match? This action cannot be undone and will remove all related data including participant registrations and scores.')) {
        const formData = new FormData();
        formData.append('action', 'delete_match');
        formData.append('match_id', matchId);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(response => {
            if (response.ok) {
                window.location.reload();
            }
        }).catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the match. Please try again.');
        });
    }
}

// Handle room details form submission
document.addEventListener('DOMContentLoaded', function() {
    const roomDetailsForm = document.getElementById('roomDetailsForm');
    if (roomDetailsForm) {
        roomDetailsForm.addEventListener('submit', function(event) {
            event.preventDefault();
            
            if (confirm('Are you sure you want to start this match?')) {
                const formData = new FormData(this);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (response.ok) {
                        roomDetailsModal.hide();
                        window.location.reload();
                    }
                }).catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while starting the match. Please try again.');
                });
            }
        });
    }
});

// Helper function to submit forms
function submitForm(action, data) {
    const form = document.createElement('form');
    form.method = 'POST';
    
    // Add action
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);
    
    // Add other data
    Object.keys(data).forEach(key => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = data[key];
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// Reset match form
function resetMatchForm() {
    const form = document.getElementById('matchForm');
    if (form) {
        form.reset();
        form.classList.remove('was-validated');
        
        // Reset hidden fields
        document.getElementById('match_id').value = '';
        
        // Reset dynamic sections
        document.getElementById('teamSection').style.display = 'none';
        document.getElementById('enableTeams').checked = false;
        
        // Reset entry fee section
        document.getElementById('entryFeeContainer').style.display = 'none';
        
        // Reset prize currency sections
        document.getElementById('realCurrencySection').style.display = 'block';
        document.getElementById('websiteCurrencySection').style.display = 'none';
        document.getElementById('useWebsiteCurrency').checked = false;
        
        // Reset modal title
        document.getElementById('addMatchModalLabel').textContent = 'Create New Match';
        
        // Reset submit button
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.textContent = 'Create Match';
        }
    }
}

// Initialize game-specific data
function initializeGameData(gameId, gameName, mapOptions, matchTypes = null) {
    // Set game ID
    document.getElementById('game_id').value = gameId;
    
    // Update modal title
    const modalTitle = document.getElementById('addMatchModalLabel');
    if (modalTitle) {
        modalTitle.textContent = `${gameName} Match Management`;
    }
    
    // Populate map options
    const mapSelect = document.getElementById('map_name');
    if (mapSelect && mapOptions) {
        // Clear existing options except the first one
        while (mapSelect.children.length > 1) {
            mapSelect.removeChild(mapSelect.lastChild);
        }
        
        // Add new map options
        mapOptions.forEach(map => {
            const option = document.createElement('option');
            option.value = map.value;
            option.textContent = map.text;
            mapSelect.appendChild(option);
        });
    }
    
    // Update match types if provided
    const matchTypeSelect = document.getElementById('match_type');
    if (matchTypeSelect && matchTypes) {
        // Clear existing options except the first one
        while (matchTypeSelect.children.length > 1) {
            matchTypeSelect.removeChild(matchTypeSelect.lastChild);
        }
        
        // Add new match type options
        matchTypes.forEach(type => {
            const option = document.createElement('option');
            option.value = type.value;
            option.textContent = type.text;
            matchTypeSelect.appendChild(option);
        });
    }
}

// Populate teams dropdown
function populateTeamsDropdown(teams) {
    const team1Select = document.getElementById('team1_id');
    const team2Select = document.getElementById('team2_id');
    
    if (team1Select && team2Select && teams) {
        [team1Select, team2Select].forEach(select => {
            // Clear existing options except the first one
            while (select.children.length > 1) {
                select.removeChild(select.lastChild);
            }
            
            // Add team options
            teams.forEach(team => {
                const option = document.createElement('option');
                option.value = team.id;
                option.textContent = team.name;
                select.appendChild(option);
            });
        });
    }
}

// Edit match function
function editMatch(matchId) {
    // This would typically fetch match data via AJAX
    // For now, we'll just show the modal and set the match ID
    document.getElementById('match_id').value = matchId;
    document.getElementById('addMatchModalLabel').textContent = 'Edit Match';
    
    const submitBtn = document.querySelector('#matchForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.textContent = 'Update Match';
    }
    
    addMatchModal.show();
}

// Confirm cancel function
function confirmCancel(event) {
    return confirm('Are you sure you want to cancel this match? This will refund all participants and cannot be undone.');
}

// Session message handler
function handleSessionMessages() {
    // This will be called by individual game files to handle PHP session messages
    // The actual PHP session message handling is done inline in each file
}
function resetMatchForm() {
    const form = document.getElementById('matchForm');
    const modalTitle = document.getElementById('addMatchModalLabel');
    const submitButton = form.querySelector('button[type="submit"]');
    
    // Reset form
    form.reset();
    form.classList.remove('was-validated');
    
    // Update form for adding
    modalTitle.textContent = 'Create New Match';
    submitButton.textContent = 'Create Match';
    form.elements['action'].value = 'add_match';
    form.elements['match_id'].value = '';
    
    // Initialize date and time
    const now = new Date();
    const today = now.toISOString().split('T')[0];
    form.elements['match_date'].value = today;
    now.setHours(now.getHours() + 1);
    now.setMinutes(0);
    form.elements['match_time'].value = now.toTimeString().slice(0, 5);
    
    // Reset entry fee
    toggleEntryFee();
}

function editMatch(matchId) {
    // Fetch match details via AJAX
    fetch(`get_match.php?id=${matchId}`)
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                throw new Error(result.error || 'Failed to fetch match data');
            }
            
            const match = result.data;
            
            // Populate form fields
            const form = document.getElementById('matchForm');
            form.elements['match_id'].value = match.id;
            form.elements['game_id'].value = match.game_id;
            form.elements['match_type'].value = match.match_type || '';
            form.elements['entry_type'].value = match.entry_type || 'free';
            form.elements['entry_fee'].value = match.entry_fee || 0;
            form.elements['max_participants'].value = match.max_participants || 10;
            form.elements['prize_type'].value = match.prize_type || 'INR';
            form.elements['prize_pool'].value = match.prize_pool || 0;
            form.elements['map_name'].value = match.map_name || '';
            form.elements['prize_distribution'].value = match.prize_distribution || 'single';
            form.elements['coins_per_kill'].value = match.coins_per_kill || 0;
            
            // Set date and time using formatted fields if available
            if (match.match_date_formatted && match.match_time_formatted) {
                form.elements['match_date'].value = match.match_date_formatted;
                form.elements['match_time'].value = match.match_time_formatted;
            } else if (match.match_date) {
                const matchDateTime = new Date(match.match_date);
                form.elements['match_date'].value = matchDateTime.toISOString().split('T')[0];
                form.elements['match_time'].value = matchDateTime.toTimeString().slice(0,5);
            }
            
            // Handle entry fee visibility
            toggleEntryFee();
            
            // Handle teams
            if (match.team1_id || match.team2_id) {
                document.getElementById('enableTeams').checked = true;
                document.getElementById('teamSection').style.display = 'block';
                if (form.elements['team1_id']) form.elements['team1_id'].value = match.team1_id || '';
                if (form.elements['team2_id']) form.elements['team2_id'].value = match.team2_id || '';
            } else {
                document.getElementById('enableTeams').checked = false;
                document.getElementById('teamSection').style.display = 'none';
            }
            
            // Handle website currency
            if (match.website_currency_type && match.website_currency_amount > 0) {
                document.getElementById('useWebsiteCurrency').checked = true;
                form.elements['website_currency_type'].value = match.website_currency_type;
                form.elements['website_currency_amount'].value = match.website_currency_amount;
                togglePrizeCurrency();
            } else {
                document.getElementById('useWebsiteCurrency').checked = false;
                togglePrizeCurrency();
            }
            
            // Handle tournament selection if present
            if (match.tournament_id && form.elements['tournament_id']) {
                form.elements['tournament_id'].value = match.tournament_id;
            }
            
            // Update form for editing
            document.getElementById('addMatchModalLabel').textContent = 'Edit Match';
            form.querySelector('button[type="submit"]').textContent = 'Update Match';
            form.elements['action'].value = 'add_match'; // Using same action as add, with match_id present
            
            // Show the modal
            addMatchModal.show();
        })
        .catch(error => {
            console.error('Error fetching match details:', error);
            alert('Failed to load match details: ' + error.message);
        });
}

// Update form submission to handle both add and edit
document.addEventListener('DOMContentLoaded', function() {
    const matchForm = document.getElementById('matchForm');
    if (matchForm) {
        matchForm.addEventListener('submit', function(event) {
            event.preventDefault();
            
            // Custom validation for prize pool
            const useWebsiteCurrency = document.getElementById('useWebsiteCurrency').checked;
            const prizePool = document.getElementById('prize_pool').value;
            const websiteCurrencyAmount = document.getElementById('website_currency_amount').value;
            
            let validationError = '';
            
            if (useWebsiteCurrency) {
                if (!websiteCurrencyAmount || websiteCurrencyAmount <= 0) {
                    validationError = 'Please enter a valid website currency amount!';
                    document.getElementById('website_currency_amount').focus();
                }
            } else {
                if (!prizePool || prizePool <= 0) {
                    validationError = 'Please enter a valid prize pool amount!';
                    document.getElementById('prize_pool').focus();
                }
            }
            
            if (validationError) {
                alert(validationError);
                return;
            }
            
            if (!this.checkValidity()) {
                event.stopPropagation();
                this.classList.add('was-validated');
                return;
            }
            
            const formData = new FormData(this);
            const isEdit = formData.get('match_id') !== '';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    addMatchModal.hide();
                    window.location.reload();
                } else {
                    return response.text().then(text => {
                        console.error('Server response:', text);
                        alert('Server error occurred. Please check the console for details.');
                    });
                }
            }).catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    }
});

function confirmCancel(event) {
    if (!confirm('Are you sure you want to cancel this match? This will refund all participants and cannot be undone.')) {
        event.preventDefault();
        return false;
    }
    return true;
}

