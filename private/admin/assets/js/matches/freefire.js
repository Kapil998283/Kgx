// Free Fire specific constants and functions
const FREEFIRE_MAPS = ['Bermuda', 'Purgatory', 'Kalahari', 'Alpine'];
const FREEFIRE_MATCH_TYPES = ['Solo', 'Duo', 'Squad', 'Clash Squad'];

// Update prize currency symbol when prize type changes
document.getElementById('prize_type').addEventListener('change', function() {
    const currencySymbol = this.value === 'INR' ? '₹' : '$';
    document.getElementById('prize-currency').textContent = currencySymbol;
});
