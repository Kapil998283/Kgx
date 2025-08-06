// PUBG specific constants and functions
const PUBG_MAPS = ['Erangel', 'Miramar', 'Sanhok', 'Vikendi', 'Karakin'];
const PUBG_MATCH_TYPES = ['Solo', 'Duo', 'Squad', 'Team Deathmatch'];

// Update prize currency symbol when prize type changes
document.getElementById('prize_type').addEventListener('change', function() {
    const currencySymbol = this.value === 'INR' ? '₹' : '$';
    document.getElementById('prize-currency').textContent = currencySymbol;
});
