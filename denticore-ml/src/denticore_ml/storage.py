"""Bucket S3 de los artefactos del modelo (DI-18). Credenciales por las variables estándar de AWS
(`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`); `ML_S3_ENDPOINT` apunta a MinIO en local."""

import os
from typing import TYPE_CHECKING, Any

import boto3

__all__ = ["S3Client", "s3_client_from_env"]

if TYPE_CHECKING:
    from mypy_boto3_s3.client import S3Client
else:
    S3Client = Any


def s3_client_from_env() -> "S3Client":
    endpoint = os.environ.get("ML_S3_ENDPOINT") or None
    region = os.environ.get("AWS_DEFAULT_REGION", "us-east-1")
    return boto3.client("s3", endpoint_url=endpoint, region_name=region)
