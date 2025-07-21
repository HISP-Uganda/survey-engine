<?php if ($activeTab == 'view') : ?>
    
    <div class="tab-header">
        <h3><i class="fas fa-map-marker-alt me-2"></i> View Locations</h3>
    </div>
    
    <div class="card">
        <div class="card-header bg-light">
            <div class="row align-items-center">
                <div class="col">
                    <h4 class="mb-0">Regions</h4>
                </div>
                <div class="col-auto">
                    <?php 
                    // Count only regions (level 2) or whatever level you consider as regions
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM location WHERE hierarchylevel = 2");
                    $regionCount = $countStmt->fetchColumn();
                    ?>
                    <span class="badge bg-primary"><?= $regionCount ?> locations</span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php 
            // Fetch regions (level 2) from the location table
            $stmt = $pdo->query("SELECT id, uid, name, path, hierarchylevel FROM location WHERE hierarchylevel = 2 ORDER BY name");
            $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($regions) > 0) : ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th>UID</th>
                                <th>Level</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regions as $region) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($region['name']) ?></td>
                                    <td><small class="text-muted"><?= $region['uid'] ?></small></td>
                                    <td><?= $region['hierarchylevel'] ?></td>
                                    <td>
                                        <a href="view_region.php?id=<?= $region['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="text-center py-5">
                    <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                    <h4>No regions found</h4>
                    <p class="text-muted">Please load metadata from DHIS2 to populate regions.</p>
                    <a href="?tab=load" class="btn btn-primary mt-3">
                        <i class="fas fa-sync-alt me-2"></i> Load Metadata
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>