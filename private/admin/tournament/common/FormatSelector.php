<?php
/**
 * Tournament Format Selector
 * Factory class to create appropriate tournament format managers
 */

require_once dirname(__DIR__) . '/group-stage/GroupStageManager.php';
require_once dirname(__DIR__) . '/weekly-finals/WeeklyFinalsManager.php';

class FormatSelector {
    
    /**
     * Get format manager for a tournament
     */
    public static function getFormatManager($format) {
        switch ($format) {
            case 'Group Stage':
                return new GroupStageManager();
                
            case 'Weekly Finals':
                return new WeeklyFinalsManager();
                
            case 'Elimination':
            case 'Custom Lobby':
                // These use the existing elimination system
                return null;
                
            default:
                throw new Exception("Unsupported tournament format: " . $format);
        }
    }
    
    /**
     * Get available tournament formats
     */
    public static function getAvailableFormats() {
        return [
            'Elimination' => [
                'name' => 'Elimination Tournament',
                'description' => 'Traditional single-elimination bracket format',
                'suitable_for' => ['Small to medium tournaments', 'Quick tournaments'],
                'participant_limit' => [16, 64],
                'duration' => '1-2 days'
            ],
            'Group Stage' => [
                'name' => 'Group Stage (BMPS Style)',
                'description' => 'Multiple groups with advancement to finals',
                'suitable_for' => ['Large tournaments', 'Professional events'],
                'participant_limit' => [80, 500],
                'duration' => '3-7 days'
            ],
            'Weekly Finals' => [
                'name' => 'Weekly Finals',
                'description' => 'Progressive weekly elimination tournament',
                'suitable_for' => ['Season-long events', 'Extended engagement'],
                'participant_limit' => [64, 1000],
                'duration' => '3-8 weeks'
            ],
            'Custom Lobby' => [
                'name' => 'Custom Lobby',
                'description' => 'Single lobby with multiple matches',
                'suitable_for' => ['Small tournaments', 'Practice events'],
                'participant_limit' => [16, 25],
                'duration' => '1 day'
            ]
        ];
    }
    
    /**
     * Check if format is supported
     */
    public static function isFormatSupported($format) {
        $supportedFormats = array_keys(self::getAvailableFormats());
        return in_array($format, $supportedFormats);
    }
    
    /**
     * Get format-specific configuration options
     */
    public static function getFormatConfigOptions($format) {
        switch ($format) {
            case 'Group Stage':
                return [
                    'num_groups' => [
                        'type' => 'select',
                        'label' => 'Number of Groups',
                        'options' => [4 => '4 Groups', 6 => '6 Groups', 8 => '8 Groups'],
                        'default' => 4,
                        'required' => true
                    ],
                    'teams_per_group' => [
                        'type' => 'number',
                        'label' => 'Teams per Group',
                        'min' => 16,
                        'max' => 25,
                        'default' => 20,
                        'required' => true
                    ],
                    'matches_per_group' => [
                        'type' => 'select',
                        'label' => 'Matches per Group',
                        'options' => [4 => '4 Matches', 6 => '6 Matches', 8 => '8 Matches'],
                        'default' => 6,
                        'required' => true
                    ],
                    'qualification_slots' => [
                        'type' => 'number',
                        'label' => 'Qualification Slots per Group',
                        'min' => 1,
                        'max' => 10,
                        'default' => 5,
                        'required' => true
                    ],
                    'finals_format' => [
                        'type' => 'select',
                        'label' => 'Finals Format',
                        'options' => [
                            'single_group' => 'Single Group Finals',
                            'multi_group' => 'Multi-Group Finals'
                        ],
                        'default' => 'single_group',
                        'required' => true
                    ]
                ];
                
            case 'Weekly Finals':
                return [
                    'total_weeks' => [
                        'type' => 'select',
                        'label' => 'Total Weeks',
                        'options' => [3 => '3 Weeks', 4 => '4 Weeks', 5 => '5 Weeks', 6 => '6 Weeks'],
                        'default' => 4,
                        'required' => true
                    ],
                    'initial_participants' => [
                        'type' => 'number',
                        'label' => 'Initial Participants',
                        'min' => 64,
                        'max' => 1000,
                        'default' => 100,
                        'required' => true
                    ],
                    'finals_participants' => [
                        'type' => 'number',
                        'label' => 'Finals Participants',
                        'min' => 8,
                        'max' => 25,
                        'default' => 16,
                        'required' => true
                    ],
                    'elimination_method' => [
                        'type' => 'select',
                        'label' => 'Elimination Method',
                        'options' => [
                            'bottom_percentage' => 'Bottom Percentage',
                            'fixed_number' => 'Fixed Number'
                        ],
                        'default' => 'bottom_percentage',
                        'required' => true
                    ]
                ];
                
            case 'Elimination':
                return [
                    'bracket_type' => [
                        'type' => 'select',
                        'label' => 'Bracket Type',
                        'options' => [
                            'single' => 'Single Elimination',
                            'double' => 'Double Elimination'
                        ],
                        'default' => 'single',
                        'required' => true
                    ],
                    'seeding_method' => [
                        'type' => 'select',
                        'label' => 'Seeding Method',
                        'options' => [
                            'random' => 'Random Seeding',
                            'ranked' => 'Skill-based Seeding'
                        ],
                        'default' => 'random',
                        'required' => true
                    ]
                ];
                
            case 'Custom Lobby':
                return [
                    'total_matches' => [
                        'type' => 'select',
                        'label' => 'Total Matches',
                        'options' => [4 => '4 Matches', 6 => '6 Matches', 8 => '8 Matches'],
                        'default' => 6,
                        'required' => true
                    ],
                    'lobby_size' => [
                        'type' => 'select',
                        'label' => 'Lobby Size',
                        'options' => [16 => '16 Teams', 20 => '20 Teams', 25 => '25 Teams'],
                        'default' => 20,
                        'required' => true
                    ]
                ];
                
            default:
                return [];
        }
    }
    
    /**
     * Validate format configuration
     */
    public static function validateFormatConfig($format, $config) {
        $configOptions = self::getFormatConfigOptions($format);
        $errors = [];
        
        foreach ($configOptions as $key => $option) {
            if ($option['required'] && !isset($config[$key])) {
                $errors[] = "Missing required configuration: " . $option['label'];
                continue;
            }
            
            if (isset($config[$key])) {
                $value = $config[$key];
                
                // Type validation
                switch ($option['type']) {
                    case 'number':
                        if (!is_numeric($value)) {
                            $errors[] = $option['label'] . " must be a number";
                            break;
                        }
                        
                        if (isset($option['min']) && $value < $option['min']) {
                            $errors[] = $option['label'] . " must be at least " . $option['min'];
                        }
                        
                        if (isset($option['max']) && $value > $option['max']) {
                            $errors[] = $option['label'] . " cannot exceed " . $option['max'];
                        }
                        break;
                        
                    case 'select':
                        if (!array_key_exists($value, $option['options'])) {
                            $errors[] = "Invalid value for " . $option['label'];
                        }
                        break;
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Get recommended participant count for format
     */
    public static function getRecommendedParticipants($format) {
        $formats = self::getAvailableFormats();
        return $formats[$format]['participant_limit'] ?? [16, 100];
    }
    
    /**
     * Check if tournament size is suitable for format
     */
    public static function isSuitableForParticipantCount($format, $participantCount) {
        $limits = self::getRecommendedParticipants($format);
        return $participantCount >= $limits[0] && $participantCount <= $limits[1];
    }
    
    /**
     * Get format recommendations based on participant count
     */
    public static function getFormatRecommendations($participantCount) {
        $recommendations = [];
        $formats = self::getAvailableFormats();
        
        foreach ($formats as $format => $info) {
            if (self::isSuitableForParticipantCount($format, $participantCount)) {
                $recommendations[] = [
                    'format' => $format,
                    'name' => $info['name'],
                    'description' => $info['description'],
                    'duration' => $info['duration']
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Initialize tournament based on format
     */
    public static function initializeTournament($tournamentId, $format, $config) {
        $manager = self::getFormatManager($format);
        
        if (!$manager) {
            // Handle existing elimination format
            return ['status' => 'success', 'message' => 'Using existing elimination system'];
        }
        
        try {
            switch ($format) {
                case 'Group Stage':
                    $groups = $manager->createGroups($tournamentId, $config);
                    return [
                        'status' => 'success',
                        'message' => 'Group stage initialized successfully',
                        'data' => ['groups' => count($groups)]
                    ];
                    
                case 'Weekly Finals':
                    $phases = $manager->createPhases($tournamentId, $config);
                    return [
                        'status' => 'success',
                        'message' => 'Weekly finals phases created successfully',
                        'data' => ['phases' => count($phases)]
                    ];
                    
                default:
                    return ['status' => 'error', 'message' => 'Unknown format'];
            }
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to initialize tournament: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get format-specific management interface URL
     */
    public static function getManagementUrl($format, $tournamentId) {
        switch ($format) {
            case 'Group Stage':
                return "tournament-group-stage.php?id=" . $tournamentId;
                
            case 'Weekly Finals':
                return "tournament-weekly-finals.php?id=" . $tournamentId;
                
            case 'Elimination':
            case 'Custom Lobby':
                return "tournament-rounds.php?id=" . $tournamentId;
                
            default:
                return "tournaments.php";
        }
    }
}
?>
