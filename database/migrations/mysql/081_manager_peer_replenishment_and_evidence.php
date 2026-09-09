<?php
declare(strict_types=1);

return [
    'version'=>'081',
    'description'=>'Explicit stock hierarchy, source-owner peer proposals and immutable multiple Quick Sale evidence',
    'preflight'=>static function (PDO $c): string {
        $tables=(int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('inventory_peer_proposals','sales_quick_sale_report_evidence')")->fetchColumn();
        $column=(int)$c->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_warehouses' AND column_name='parent_warehouse_id'")->fetchColumn();
        if ($tables===0 && $column===0) return 'apply';
        if ($tables===2 && $column===1) {
            $keys=(int)$c->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND constraint_name IN('fk_warehouse_parent','fk_peer_source_location','fk_peer_destination_location','fk_peer_product','fk_peer_request_line','fk_peer_line','fk_report_evidence_report')")->fetchColumn();
            $missing=(int)$c->query("SELECT COUNT(*) FROM sales_quick_sale_reports r WHERE r.evidence_path IS NOT NULL AND r.evidence_path<>'' AND NOT EXISTS(SELECT 1 FROM sales_quick_sale_report_evidence e WHERE e.company_id=r.company_id AND e.report_id=r.report_id AND e.sequence=1)")->fetchColumn();
            if ($keys===7 && $missing===0) return 'baseline';
        }
        throw new RuntimeException('Migration 081 is partially applied. Complete or restore it before retrying; do not manufacture historical decisions.');
    },
    'statements'=>[
        <<<'SQL'
ALTER TABLE inventory_warehouses
 ADD COLUMN parent_warehouse_id BIGINT UNSIGNED NULL,
 ADD INDEX idx_warehouse_parent(company_id,parent_warehouse_id),
 ADD CONSTRAINT fk_warehouse_parent FOREIGN KEY(company_id,parent_warehouse_id) REFERENCES inventory_warehouses(company_id,warehouse_id)
SQL,
        <<<'SQL'
CREATE TABLE inventory_peer_proposals (
 proposal_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 proposal_number VARCHAR(40) NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL,
 request_line_id BIGINT UNSIGNED NOT NULL,
 source_authority_id BIGINT UNSIGNED NOT NULL,
 destination_authority_id BIGINT UNSIGNED NOT NULL,
 source_warehouse_id BIGINT UNSIGNED NOT NULL,
 source_location_id BIGINT UNSIGNED NOT NULL,
 destination_warehouse_id BIGINT UNSIGNED NOT NULL,
 destination_location_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 proposed_by BIGINT UNSIGNED NOT NULL,
 source_owner_user_id BIGINT UNSIGNED NOT NULL,
 destination_owner_user_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(15,3) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'proposed',
 source_decided_by BIGINT UNSIGNED NULL,
 source_decided_at DATETIME NULL,
 rejection_reason VARCHAR(1000) NULL,
 transfer_line_id BIGINT UNSIGNED NULL,
 cancelled_by BIGINT UNSIGNED NULL,
 cancelled_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_peer_number(company_id,proposal_number),
 UNIQUE KEY uq_peer_transfer_line(company_id,transfer_line_id),
 CONSTRAINT ck_peer_quantity CHECK(quantity>0),
 CONSTRAINT ck_peer_state CHECK(state IN('proposed','source_approved','source_rejected','dispatched','completed','cancelled')),
 CONSTRAINT fk_peer_request FOREIGN KEY(company_id,request_id) REFERENCES inventory_stock_requests(company_id,request_id),
 CONSTRAINT fk_peer_request_line FOREIGN KEY(company_id,request_id,request_line_id) REFERENCES inventory_stock_request_lines(company_id,request_id,request_line_id),
 CONSTRAINT fk_peer_source FOREIGN KEY(company_id,source_authority_id) REFERENCES inventory_stock_authorities(company_id,authority_id),
 CONSTRAINT fk_peer_destination FOREIGN KEY(company_id,destination_authority_id) REFERENCES inventory_stock_authorities(company_id,authority_id),
 CONSTRAINT fk_peer_source_location FOREIGN KEY(company_id,source_warehouse_id,source_location_id) REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id),
 CONSTRAINT fk_peer_destination_location FOREIGN KEY(company_id,destination_warehouse_id,destination_location_id) REFERENCES inventory_warehouse_locations(company_id,warehouse_id,location_id),
 CONSTRAINT fk_peer_product FOREIGN KEY(company_id,product_id) REFERENCES sales_products(company_id,product_id),
 CONSTRAINT fk_peer_proposer FOREIGN KEY(company_id,proposed_by) REFERENCES company_users(company_id,user_id),
 CONSTRAINT fk_peer_source_owner FOREIGN KEY(company_id,source_owner_user_id) REFERENCES company_users(company_id,user_id),
 CONSTRAINT fk_peer_destination_owner FOREIGN KEY(company_id,destination_owner_user_id) REFERENCES company_users(company_id,user_id),
 CONSTRAINT fk_peer_decider FOREIGN KEY(company_id,source_decided_by) REFERENCES company_users(company_id,user_id),
 CONSTRAINT fk_peer_canceller FOREIGN KEY(company_id,cancelled_by) REFERENCES company_users(company_id,user_id),
 CONSTRAINT fk_peer_line FOREIGN KEY(company_id,transfer_line_id) REFERENCES inventory_transfer_lines(company_id,transfer_line_id),
 INDEX idx_peer_request(company_id,request_id,state),
 INDEX idx_peer_source(company_id,source_authority_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE sales_quick_sale_report_evidence (
 evidence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 report_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 storage_path VARCHAR(500) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 sequence TINYINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_report_evidence_sequence(company_id,report_id,sequence),
 CONSTRAINT ck_report_evidence_sequence CHECK(sequence BETWEEN 1 AND 10),
 CONSTRAINT fk_report_evidence_report FOREIGN KEY(company_id,report_id) REFERENCES sales_quick_sale_reports(company_id,report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO sales_quick_sale_report_evidence(company_id,report_id,original_name,storage_path,mime_type,file_size,sha256,sequence,created_at)
SELECT company_id,report_id,COALESCE(evidence_original_name,'Legacy evidence'),evidence_path,COALESCE(evidence_mime,'application/octet-stream'),COALESCE(evidence_size,0),COALESCE(evidence_sha256,''),1,created_at
FROM sales_quick_sale_reports WHERE evidence_path IS NOT NULL AND evidence_path<>''
SQL,
    ],
];
