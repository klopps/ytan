-- The NGA List of Lights as read from the Maritime Safety Information web service
-- (structured JSON per volume, positions to the second) - next to 'nga', the same
-- list read from its PDF.
INSERT INTO data_source (code, name, url, license, license_url, attribution) VALUES
('ngaapi', 'NGA List of Lights (MSI)', 'https://msi.nga.mil/', 'Public Domain (US-Regierungswerk, 17 U.S.C. § 105)', 'https://www.law.cornell.edu/uscode/text/17/105',
 'List of Lights, Radio Aids and Fog Signals, National Geospatial-Intelligence Agency (NGA), Maritime Safety Information');
