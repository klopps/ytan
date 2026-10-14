-- Where imported data comes from: one row per source (list, service), with the
-- name, link, licence and attribution text to show wherever its data appears.
-- lighthouse_import.source and lighthouse.data_source point at it.
CREATE TABLE data_source (
    code VARCHAR(10) NOT NULL,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(300) NULL,
    license VARCHAR(100) NULL,
    license_url VARCHAR(300) NULL,
    attribution VARCHAR(500) NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO data_source (code, name, url, license, license_url, attribution) VALUES
('dk', 'Dansk Fyrliste', 'https://www.soefartsstyrelsen.dk/', 'Veröffentlicht von der Søfartsstyrelsen (keine ausdrückliche Lizenz)', NULL,
 'Dansk Fyrliste, Søfartsstyrelsen (Danish Maritime Authority)'),
('nga', 'NGA List of Lights', 'https://msi.nga.mil/', 'Public Domain (US-Regierungswerk, 17 U.S.C. § 105)', 'https://www.law.cornell.edu/uscode/text/17/105',
 'List of Lights, Radio Aids and Fog Signals, National Geospatial-Intelligence Agency (NGA)'),
('osm', 'OpenStreetMap / OpenSeaMap', 'https://www.openstreetmap.org/', 'ODbL 1.0', 'https://opendatacommons.org/licenses/odbl/1-0/',
 '© OpenStreetMap-Mitwirkende');
